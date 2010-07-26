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
    $this->addOptions(array(
      new sfCommandOption('start-member-id', null, sfCommandOption::PARAMETER_OPTIONAL, 'Start member id', null),
      new sfCommandOption('end-member-id', null, sfCommandOption::PARAMETER_OPTIONAL, 'End member id', null),
      new sfCommandOption('subject', null, sfCommandOption::PARAMETER_OPTIONAL, 'The subject template of mail', '{{ member.name }} さんのフレンドに誕生日の近い方がいます！')
    ));
    $this->detailedDescription = <<<EOF
The [openpne:send-birthday-mail|INFO] task does things.
Call it with:

  [php symfony openpne:send-birthday-mail-lite|INFO]
EOF;
  }

  protected function execute($arguments = array(), $options = array())
  {
    parent::execute($arguments, $options);
    $this->mailLog('starting openpne:send-birthday-mail-lite task');

    // load templates
    list($pcTitleTpl, $pcTpl) = $this->getTwigTemplate('pc', 'birthday_lite');

    $birthday = $this->fetchRow('SELECT id FROM '.$this->getTableName('Profile').' WHERE name = ?', array('op_preset_birthday'));
    if (!$birthday)
    {
      throw new sfException('This project doesn\'t have the op_preset_birthday profile item.');
    }

    $birthDatetime = new DateTime();
    $birthDatetime->modify('+ 1 week');

    $query = 'SELECT member_id FROM '.$this->getTableName('MemberProfile').' WHERE profile_id = ? AND DATE_FORMAT(value_datetime, ?) = ?';
    $params = array($birthday['id'], '%m-%d', $birthDatetime->format('m-d'));
    if (null !== $options['start-member-id'] && is_numeric($options['start-member-id']))
    {
      $query .= ' AND member_id >= ?';
      $params[] = $options['start-member-id'];
    }
    if (null !== $options['end-member-id'] && is_numeric($options['end-member-id']))
    {
      $query .= ' AND member_id <= ?';
      $params[] = $options['end-member-id'];
    }

    $memberProfilesStmt = $this->executeQuery($query, $params);
    if ($memberProfilesStmt instanceof PDOStatement)
    {
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
            'birthMember' => $birthMember,
            'op_config' => $op_config,
            'sf_config' => $sf_config,
          );
          $subject = $pcTitleTpl->render($params);
          $body = $pcTpl->render($params);

          try
          {
            $this->sendMail($subject, $pcAddress, $this->adminMailAddress, $body);
            $this->mailLog(sprintf("sent member %d birthday notification mail to member %d (usage memory:%s bytes)",
              $birthMember['id'], $member['id'], number_format(memory_get_usage()))
            );
          }
          catch(Zend_Mil_Transport_Exception $e)
          {
            $this->mailLog(sprintf("%s (about member %d birthday to member %d)",$e->getMessage(), $birthMember['id'], $member['id']));
          }
        }
      }
    }
    $this->mailLog('end openpne:send-birthday-mail-lite task');
  }
}
