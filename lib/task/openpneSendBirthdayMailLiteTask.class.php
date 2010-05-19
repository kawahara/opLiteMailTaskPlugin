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

    // load templates
    list($pcTitleTpl, $pcTpl) = $this->getTwigTemplate('pc', 'birthday_lite');

    $profileTable = Doctrine::getTable('Profile');
    $connection = $profileTable->getConnection();
    $tableName = $profileTable->getTableName();
    $birthday = $connection->fetchRow('SELECT id FROM '.$tableName.' WHERE name = ?', array('op_preset_birthday'));
    if (!$birthday)
    {
      throw new sfException('This project doesn\'t have the op_preset_birthday profile item.');
    }

    $birthDatetime = new DateTime();
    $birthDatetime->modify('+ 1 week');

    $memberProfileTable = Doctrine::getTable('MemberProfile');
    $connection = $memberProfileTable->getConnection();
    $tableName  = $memberProfileTable->getTableName();
    $memberProfilesStmt = $connection->execute('SELECT member_id FROM '.$tableName.' WHERE profile_id = ? AND DATE_FORMAT(value_datetime, ?) = ?',
      array($birthday['id'], '%m-%d', $birthDatetime->format('m-d'))
    );

    $sf_config = sfConfig::getAll();
    $op_config = new opConfig();

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
          'op_config' => $op_config,
          'sf_config' => $sf_config,
        );
        $subject = $pcTitleTpl->render($params);
        $body = $pcTpl->render($params);

        try
        {
          $this->sendMail($subject, $pcAddress, $this->adminMailAddress, $body);
        }
        catch(Zend_Mail_Transport_Exception $e)
        {
        }
      }
    }
  }
}
