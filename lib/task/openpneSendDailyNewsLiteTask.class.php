<?php

/**
 * This file is part of the OpenPNE package.
 * (c) OpenPNE Project (http://www.openpne.jp/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file and the NOTICE file that were distributed with this source code.
 */

/**
 * openpneSendDailyNewsLiteTask
 *
 * @package    opLiteMailTaskPlugin
 * @subpackage task
 * @author     Shogo Kawahara <kawahara@bucyou.net>
 */
class openpneSendDailyNewsLiteTask extends opBaseSendMailLiteTask
{
  protected function configure()
  {
    parent::configure();
    $this->namespace        = 'openpne';
    $this->name             = 'send-daily-news-lite';
    $this->briefDescription = '';
    $this->addOptions(array(
      new sfCommandOption('start-member-id', null, sfCommandOption::PARAMETER_OPTIONAL, 'Start member id', null),
      new sfCommandOption('end-member-id', null, sfCommandOption::PARAMETER_OPTIONAL, 'End member id', null),
    ));
    $this->detailedDescription = <<<EOF
The [openpne:send-daily-news-lite|INFO] task does things.
Call it with:

  [php symfony openpne:send-daily-news-lite|INFO]
EOF;
  }

  protected function getFriendDiaryList($memberId, $limit = 5)
  {
    $friendIds = $this->getFriendIds($memberId);
    if (!$friendIds)
    {
      return array();
    }

    $sql = 'SELECT id, member_id, title FROM '.$this->getTableName('Diary')
         . ' WHERE member_id IN ('.implode(',', $friendIds). ')'
         . ' AND public_flag IN (1, 2)'
         . ' ORDER BY created_at DESC'
         . ' LIMIT '.$limit;

    $stmt = $this->executeQuery($sql);
    $results = array();
    while ($r = $stmt->fetch(Doctrine::FETCH_ASSOC))
    {
      $r['member'] = $this->getMember($r['member_id']);
      $results[] = $r;
    }

    return $results;
  }

  protected function getCommunity($communityId)
  {
    return $this->fetchRow('SELECT id, name FROM '.$this->getTableName('Community').' WHERE id = ?', array($communityId));
  }

  protected function getJoinCommnityIds($memberId)
  {
    $results = array();

    $stmt =  $this->executeQuery('SELECT community_id FROM '.$this->getTableName('CommunityMember').' WHERE member_id = ? AND is_pre = 0', array($memberId));
    while ($r = $stmt->fetch(Doctrine::FETCH_NUM))
    {
      $results[] = $r[0];
    }

    return $results;
  }

  protected function getCommunityTopicList($memberId, $limit = 5)
  {
    $communityIds = $this->getJoinCommnityIds($memberId);
    if (!$communityIds)
    {
      return array();
    }

    $sql = 'SELECT id, community_id, member_id, name, body FROM '.$this->getTableName('CommunityTopic')
         . ' WHERE community_id IN ('.implode(',', $communityIds).')'
         . ' ORDER BY updated_at DESC'
         . ' LIMIT '.$limit;

    $stmt = $this->executeQuery($sql);
    $results = array();
    while ($r = $stmt->fetch(Doctrine::FETCH_ASSOC))
    {
      $r['community'] = $this->getCommunity($r['community_id']);
      $results[] = $r;
    }

    return $results;
  }

  protected function getUnreadMessageList($memberId, $limit = 5)
  {
    if (!class_exists('SendMessageData') || !class_exists('MessageSendList'))
    {
      return array();
    }

    $sql = 'SELECT id, subject, body, member_id'
         . ' FROM '.$this->getTableName('SendMessageData')
         . ' WHERE is_send = 1'
         . ' AND id IN ('
         . '   SELECT message_id'
         . '   FROM '.$this->getTableName('MessageSendList')
         . '   WHERE member_id = ?'
         . '   AND is_deleted = 0'
         . '   AND is_read = 0'
         . ' ) ORDER BY updated_at DESC'
         . ' LIMIT '.(int)$limit;

    $stmt = $this->executeQuery($sql, array((int)$memberId));
    $results = array();
    while ($r = $stmt->fetch(Doctrine::FETCH_ASSOC))
    {
      $r['member'] = $this->getMember($r['member_id']);
      $results[] = $r;
    }

    return $results;
  }

  protected function getDailyNewsConfig($memberId)
  {
    $result = $this->fetchRow("SELECT value FROM member_config WHERE member_id = ? AND name = 'daily_news'", array($memberId));

    if ($result)
    {
      return $result['value'];
    }

    return false;
  }

  protected function isDailyNewsDay()
  {
    $day = date('w') - 1;
    if (0 > $day)
    {
      $day = 7;
    }

    return in_array($day, $this->dailyNewsDays);
  }

  protected function execute($arguments = array(), $options = array())
  {
    parent::execute($arguments, $options);
    $this->mailLog('starting openpne:send-daily-news-lite task');

    $this->dailyNewsDays = opConfig::get('daily_news_day');
    $today = time();

    // load templates
    list ($titleTpl, $tpl) = $this->getTwigTemplate('pc', 'dailyNews_lite');

    $query = 'SELECT id, name FROM '.$this->getTableName('Member').' WHERE (is_active = 1 OR is_active IS NULL)';
    $params = array();
    if (null !== $options['start-member-id'] && is_numeric($options['start-member-id']))
    {
      $query .= ' AND id >= ?';
      $params[] = $options['start-member-id'];
    }
    if (null !== $options['end-member-id'] && is_numeric($options['end-member-id']))
    {
      $query .= ' AND id <= ?';
      $params[] = $options['end-member-id'];
    }

    $stmtMember = $this->executeQuery($query, $params);
    if ($stmtMember instanceof PDOStatement)
    {
      $sf_config = sfConfig::getAll();
      $op_config = new opConfig();

      $isDailyNewsDay = $this->isDailyNewsDay();

      while ($member = $stmtMember->fetch(Doctrine::FETCH_ASSOC))
      {
        $config = $this->getDailyNewsConfig($member['id']);
        if (1 == $config && !$isDailyNewsDay)
        {
          continue;
        }

        if (false !== $config && !$config)
        {
          continue;
        }

        $address = $this->getMemberPcEmailAddress($member['id']);
        if (!$address)
        {
          continue;
        }

        $params = array(
          'member'    => $member,
          'subject'   => $template['title'],
          'diaries'   => $this->getFriendDiaryList($member['id']),
          'communityTopics' => $this->getCommunityTopicList($member['id']),
          'unreadMessages' => $this->getUnreadMessageList($member['id']),
          'today'     => $today,
          'op_config' => $op_config,
          'sf_config' => $sf_config,
        );

        $subject = $titleTpl->render($params);
        $body = $tpl->render($params);

        try
        {
          $this->sendMail($subject, $address, $this->adminMailAddress, $body);
          $this->mailLog(sprintf("sent daily news to member %d (usage memory:%s bytes)", $member['id'], number_format(memory_get_usage())));
        }
        catch (Zend_Mail_Transport_Exception $e)
        {
          $this->mailLog(sprintf("%s (member %d)",$e->getMessage(), $member['id']), sfLogger::ERR);
        }
      }
    }
    $this->mailLog('end openpne:send-daily-news-lite task');
  }
}
