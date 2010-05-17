<?php

/**
 * This file is part of the OpenPNE package.
 * (c) OpenPNE Project (http://www.openpne.jp/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file and the NOTICE file that were distributed with this source code.
 */

/**
 * opBaseSendMailLiteTask
 *
 * @package    opLiteMailTaskPlugin
 * @subpackage task
 * @author     Shogo Kawahara <kawahara@bucyou.net>
 */
abstract class opBaseSendMailLiteTask extends opBaseSendMailTask
{
  protected
    $inactiveMemberIds = null,
    $transport = null,
    $sendCount = 0;

  protected function getMember($memberId)
  {
    $memberTable = Doctrine::getTable('Member');
    $connection = $memberTable->getConnection();
    $tableName = $memberTable->getTableName();
    return $connection->fetchRow("SELECT id, name FROM ".$tableName." WHERE (is_active = 1 OR is_active IS NULL) AND id = ?", array($memberId));
  }

  protected function getInactiveMemberIds()
  {
    if (null !== $this->inactiveMemberIds)
    {
      return $this->inactiveMemberIds;
    }

    $memberTable = Doctrine::getTable('Member');
    $connection = $memberTable->getConnection();
    $tableName = $memberTable->getTableName();

    $results = array();
    $stmt =  $connection->execute('SELECT id FROM '.$tableName.' WHERE is_active = 0');
    while ($r = $stmt->fetch(Doctrine::FETCH_NUM))
    {
      $results[] = $r[0];
    }
    $this->inactiveMemberIds = $results;
    return $results;
  }

  protected function getFriendIds($memberId)
  {
    $this->getInactiveMemberIds();

    $results = array();

    $memberRelationshipTable = Doctrine::getTable('MemberRelationship');
    $connection = $memberRelationshipTable->getConnection();
    $tableName = $memberRelationshipTable->getTableName();

    $stmt =  $connection->execute('SELECT member_id_to FROM '.$tableName.' WHERE member_id_from = ? AND is_friend = 1', array($memberId));
    while ($r = $stmt->fetch(Doctrine::FETCH_NUM))
    {
      if (!in_array($r[0], $this->inactiveMemberIds))
      {
        $results[] = $r[0];
      }
    }
    return $results;
  }

  protected function getMemberPcEmailAddress($memberId)
  {
    $memberConfigTable = Doctrine::getTable('MemberConfig');
    $connection = $memberConfigTable->getConnection();
    $tableName = $memberConfigTable->getTableName();
    $memberConfig = $connection->fetchRow("SELECT value FROM ".$tableName." WHERE name = 'pc_address' AND member_id = ?", array($memberId));
    if ($memberConfig)
    {
      return $memberConfig['value'];
    }
    return false;
  }

  protected function getMemberMobileEmailAddress($memberId)
  {
    $memberConfigTable = Doctrine::getTable('MemberConfig');
    $connection = $memberConfigTable->getConnection();
    $tableName = $memberConfigTable->getTableName();
    $memberConfig = $connection->fetchRow("SELECT value FROM ".$tableName." WHERE name = 'mobile_address' AND member_id = ?", array($memberId));
    if ($memberConfig)
    {
      return $memberConfig['value'];
    }
    return false;
  }

  protected function getMailTemplate($env, $templateName, $require = false)
  {
    // First, load tempalte from DB.
    $notificationMailTable = Doctrine::getTable('NotificationMail');
    $connection = $notificationMailTable->getConnection();
    $tableName = $notificationMailTable->getTableName();

    $notificationMail = $connection->fetchRow("SELECT id FROM ".$tableName." WHERE name = ?", array($env.'_'.$templateName));

    if ($notificationMail)
    {
      $notificationMailTransTable = Doctrine::getTable('NotificationMailTranslation');
      $connection = $notificationMailTransTable->getConnection();
      $tableName = $notificationMailTransTable->getTableName();

      $notificationMailTrans = $connection->fetchRow("SELECT title, template FROM ".$tableName." WHERE id = ? AND lang = 'ja_JP'", array($notificationMail['id']));
      if ($notificationMailTrans)
      {
        return $notificationMailTrans;
      }
    }

    // Secound, load default template from "config/mail_template.yml".
    $mailTemplateConfig = include(sfContext::getInstance()->getConfigCache()->checkConfig('config/mail_template.yml'));
    $culture = sfContext::getInstance()->getUser()->getCulture();
    $config = isset($mailTemplateConfig[$env][$templateName]) ? $mailTemplateConfig[$env][$templateName] : null;
    $sample = isset($config['sample'][$culture]) ? $config['sample'][$culture] : null;
    if (is_array($sample) && count($sample) >= 2)
    {
      return array('title' => $sample[0], 'template' => $sample[1]);
    }
    else if ($sample)
    {
      return array('title' => '', 'template' => $sample);
    }

    // If $require is true, throw LogicException.
    if ($require)
    {
      throw new LogicException(sprintf("Not found template: %s", $templateName));
    }

    // If $require is false, return false.
    return false;
  }

  protected function getTransport()
  {
    if ($host = sfConfig::get('op_mail_smtp_host'))
    {
      $transport = new Zend_Mail_Transport_Smtp($host, sfConfig::get('op_mail_smtp_config', array()));
    }
    elseif ($envelopeFrom = sfConfig::get('op_mail_envelope_from'))
    {
      $transport = new Zend_Mail_Transport_Sendmail('-f'.$envelopeFrom);
    }
    else
    {
      $transport = new Zend_Mail_Transport_Sendmail();
    }
    return $transport;
  }

  protected function sendMail($subject, $address, $from, $body)
  {
    if (null === $this->transport)
    {
      $this->transport = $this->getTransport();
    }

    // This code prevents memory leak.
    $this->sendCount++;
    if ($this->sendCount > 100)
    {
      unset($this->transport);
      $this->sendCount = 0;
      $this->transport = $this->getTransport();
    }

    $subject = mb_convert_kana($subject, 'KV');

    $mailer = new Zend_Mail('iso-2022-jp');
    $mailer->setHeaderEncoding(Zend_Mime::ENCODING_BASE64)
      ->setFrom($from)
      ->addTo($address)
      ->setSubject(mb_encode_mimeheader($subject, 'iso-2022-jp'))
      ->setBodyText(mb_convert_encoding($body, 'JIS', 'UTF-8'), 'iso-2022-jp', Zend_Mime::ENCODING_7BIT);

    if ($envelopeFrom = sfConfig::get('op_mail_envelope_from'))
    {
      $mailer->setReturnPath($envelopeFrom);
    }
    $mailer->send($this->transport);
  }
}
