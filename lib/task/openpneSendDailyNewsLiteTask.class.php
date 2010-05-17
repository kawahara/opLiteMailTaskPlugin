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

    $diaryTable = Doctrine::getTable('Diary');
    $connection = $diaryTable->getConnection();
    $tableName  = $diaryTable->getTableName();

    $sql = 'SELECT id, member_id, title FROM '.$tableName
         . ' WHERE member_id IN ('.implode(',', $friendIds). ')'
         . ' AND public_flag IN (1, 2)'
         . ' ORDER BY created_at DESC'
         . ' LIMIT '.$limit;

    $stmt = $connection->execute($sql);
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
    $communityTable = Doctrine::getTable('Community');
    $connection = $communityTable->getConnection();
    $tableName  = $communityTable->getTableName();
    return $connection->fetchRow("SELECT id, name FROM ".$tableName." WHERE id = ?", array($communityId));
  }

  protected function getJoinCommnityIds($memberId)
  {
    $results = array();

    $communityMemberTable = Doctrine::getTable('CommunityMember');
    $connection = $communityMemberTable->getConnection();
    $tableName  = $communityMemberTable->getTableName();
    $stmt =  $connection->execute("SELECT community_id FROM ".$tableName." WHERE member_id = ? AND is_pre = 0", array($memberId));
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

    $communityTopicTable = Doctrine::getTable('CommunityTopic');
    $connection = $communityTopicTable->getConnection();
    $tableName  = $communityTopicTable->getTableName();

    $sql = 'SELECT id, community_id, member_id, name, body FROM '.$tableName
         . ' WHERE community_id IN ('.implode(',', $communityIds).')'
         . ' ORDER BY updated_at DESC'
         . ' LIMIT '.$limit;

    $stmt = $connection->execute($sql);
    $results = array();
    while ($r = $stmt->fetch(Doctrine::FETCH_ASSOC))
    {
      $r['community'] = $this->getCommunity($r['community_id']);
      $results[] = $r;
    }
    return $results;
  }

  protected function getDailyNewsConfig($memberId)
  {
    $memberConfigTable = Doctrine::getTable('MemberConfig');
    $connection = $memberConfigTable->getConnection();
    $tableName  = $memberConfigTable->getTableName();
    $result = $connection->fetchRow("SELECT value FROM ".$tableName." WHERE member_id = ? AND name = 'daily_news'", array($memberId));
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
    sfContext::createInstance($this->createConfiguration('pc_frontend', 'prod'), 'pc_frontend');

    $adminMailAdress = opConfig::get('admin_mail_address');
    $this->dailyNewsDays = opConfig::get('daily_news_day');
    $today = time();

    // load tempaltes
    $template = $this->getMailTemplate('pc', 'dailyNews_lite', true);
    $signature = $this->getMailTemplate('pc', 'signature');
    if ($signature)
    {
      $template['template'] =  $template['template']."\n".$signature['template'];
    }

    $helpers = array_unique(array_merge(array('Helper', 'Url', 'Asset', 'Tag', 'Escaping'), sfConfig::get('sf_standard_helpers')));
    sfContext::getInstance()->getConfiguration()->loadHelpers($helpers);

    $sf_config = sfConfig::getAll();

    $twigEnvironment = new Twig_Environment(new Twig_Loader_String());
    $tpl = $twigEnvironment->loadTemplate($template['template']);

    sfOpenPNEApplicationConfiguration::registerZend();

    $memberTable = Doctrine::getTable('Member');
    $connection  = $memberTable->getConnection();
    $tableName   = $memberTable->getTableName();
    $stmtMember = $connection->execute("SELECT id, name FROM ".$tableName." WHERE is_active = 1 OR is_active IS NULL");
    while ($member = $stmtMember->fetch(Doctrine::FETCH_ASSOC))
    {
      $config = $this->getDailyNewsConfig($member['id']);

      if (1 == $config && !$this->isDailyNewsDay())
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
        'communityTopics' => array(),
        'diaries'   => $this->getFriendDiaryList($member['id']),
        'communityTopics' => $this->getCommunityTopicList($member['id']),
        'today'     => $today,
        'op_config' => new opConfig(),
        'sf_config' => $sf_config,
      );

      $body = $tpl->render($params);

      try
      {
        $this->sendMail($template['title'], $address, $adminMailAdress, $body);
      }
      catch (Zend_Mail_Transport_Exception $e)
      {
      }
    }
  }
}
