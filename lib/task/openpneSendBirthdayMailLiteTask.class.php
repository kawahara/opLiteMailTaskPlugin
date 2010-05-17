<?php

/**
 * This file is part of the OpenPNE package.
 * (c) OpenPNE Project (http://www.openpne.jp/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file and the NOTICE file that were distributed with this source code.
 */

/**
 * openpneSendBirthdayMailLiteTask
 *
 * @package    opLiteMailTaskPlugin
 * @subpackage task
 * @author     Shogo Kawahara <kawahara@bucyou.net>
 */
class openpneSendBirthdayMailLiteTask extends opBaseSendMailLiteTask
{
  protected function configure()
  {
    parent::configure();
    $this->namespace        = 'openpne';
    $this->name             = 'send-birthday-mail-lite';
    $this->briefDescription = '';
    $this->detailedDescription = <<<EOF
The [openpne:send-birthday-mail|INFO] task does things.
Call it with:

  [php symfony openpne:send-birthday-mail-lite|INFO]
EOF;
  }

  protected function execute($arguments = array(), $options = array())
  {
    parent::execute($arguments, $options);
    sfContext::createInstance($this->createConfiguration('pc_frontend', 'prod'), 'pc_frontend');

    // load templates
    $pcTemplate     = $this->getMailTemplate('pc', 'birthday_lite', true);
    $pcSignature    = $this->getMailTemplate('pc', 'signature');
    $pcTemplate['template'] = $pcTemplate['template']."\n".$pcSignature['template'];

    $profileTable = Doctrine::getTable('Profile');
    $connection = $profileTable->getConnection();
    $tableName = $profileTable->getTableName();
    $birthday = $connection->fetchRow('SELECT id FROM '.$tableName.' WHERE name = ?', array('op_preset_birthday'));
    if (!$birthday)
    {
      throw new sfException('This project doesn\'t have the op_preset_birthday profile item.');
    }
    $helpers = array_unique(array_merge(array('Helper', 'Url', 'Asset', 'Tag', 'Escaping'), sfConfig::get('sf_standard_helpers')));
    sfContext::getInstance()->getConfiguration()->loadHelpers($helpers);

    $twigEnvironment = new Twig_Environment(new Twig_Loader_String());

    $pcTitleTpl = $twigEnvironment->loadTemplate($pcTemplate['title']);
    $pcTpl = $twigEnvironment->loadTemplate($pcTemplate['template']);

    $adminMailAdress = opConfig::get('admin_mail_address');

    sfOpenPNEApplicationConfiguration::registerZend();

    $birthDatetime = new DateTime();
    $birthDatetime->modify('+ 1 week');

    $memberProfileTable = Doctrine::getTable('MemberProfile');
    $connection = $memberProfileTable->getConnection();
    $tableName  = $memberProfileTable->getTableName();
    $memberProfilesStmt = $connection->execute('SELECT member_id FROM '.$tableName.' WHERE profile_id = ? AND DATE_FORMAT(value_datetime, ?) = ?',
      array($birthday['id'], '%m-%d', $birthDatetime->format('m-d'))
    );

    $sf_config = sfConfig::getAll();
    while ($memberProfile = $memberProfilesStmt->fetch(Doctrine::FETCH_NUM))
    {
      $birthMember = $this->getMember($memberProfile[0]);
      $birthMember['birthday'] = $birthDatetime->format('U');
      $ids = $this->getFriendIds($memberProfile[0]);
      foreach ($ids as $id)
      {
        $member = $this->getMember($id);
        $pcAddress = $this->getMemberPcEmailAddress($id);
        if (!$pcAddress)
        {
          continue;
        }

        $params = array(
          'member' => $member,
          'subject' => $pcTemplate['title'],
          'birthMember' => $birthMember,
          'base_url' => sfConfig::get('op_base_url'),
          'op_config' => new opConfig(),
          'sf_config' => $sf_config,
        );
        $subject = $pcTitleTpl->render($params);
        $body = $pcTpl->render($params);

        try
        {
          $this->sendMail($subject, $pcAddress, $adminMailAdress, $body);
        }
        catch(Zend_Mail_Transport_Exception $e)
        {
        }
      }
    }
  }
}
