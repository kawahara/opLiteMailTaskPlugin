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
    $sendCount = 0,
    $adminMailAddress = null,
    $connectionOptions = null,
    $tables = array(),
    $tableNames = array(),
    $logger = null,
    $subject = null;

  protected function configure()
  {
    parent::configure();
    $this->addOption('log-file', 'l', sfCommandOption::PARAMETER_OPTIONAL, 'The path of log file', null);
  }

  protected function execute($arguments = array(), $options = array())
  {
    parent::execute($arguments, $options);
    $connection = Doctrine_Manager::connection();
    $this->connectionOptions = $connection->getOptions();

    if (isset($options['subject']) && $options['subject'])
    {
      $this->subject = $options['subject'];
    }

    sfContext::createInstance($this->createConfiguration('pc_frontend', 'prod'), 'pc_frontend');
    sfOpenPNEApplicationConfiguration::registerZend();

    $this->adminMailAddress = opConfig::get('admin_mail_address');

    $helpers = array_unique(array_merge(array('Helper', 'Url', 'Asset', 'Tag', 'Escaping'), sfConfig::get('sf_standard_helpers')));
    sfContext::getInstance()->getConfiguration()->loadHelpers($helpers);

    if (null !== $options['log-file'])
    {
      $this->logger = new sfFileLogger($this->dispatcher, array('file' => $options['log-file']));
    }
  }

  protected function getDbh()
  {
    $options = $this->connectionOptions;
    $dbh =  new PDO($options['dsn'], $options['username'],
      (!$options['password'] ? '':$options['password']), $options['other']);

    if (0 === strpos($options['dsn'], 'mysql:'))
    {
      $dbh->query('SET NAMES utf8');
    }

    return $dbh;
  }

  protected function executeQuery($query, $params = array())
  {
    if (!empty($params))
    {
      $stmt = $this->getDbh()->prepare($query);
      $stmt->execute($params);

      return $stmt;
    }

    return $this->getDbh()->query($query);
  }

  protected function fetchRow($query, $params = array())
  {
    return $this->executeQuery($query, $params)->fetch(Doctrine_Core::FETCH_ASSOC);
  }

  protected function mailLog($message, $priority = sfLogger::INFO)
  {
    if (null !== $this->logger)
    {
      $this->logger->log($message, $priority);
    }
  }

  protected function getTable($modelName)
  {
    if (!isset($this->tables[$modelName]))
    {
      $this->tables[$modelName] = Doctrine::getTable($modelName);
    }

    return $this->tables[$modelName];
  }

  protected function getTableName($modelName)
  {
    if (!isset($this->tableNames[$modelName]))
    {
      $this->tableNames[$modelName] = $this->getTable($modelName)->getTableName();
    }

    return $this->tableNames[$modelName];
  }

  protected function getMember($memberId)
  {
    return $this->fetchRow('SELECT id, name FROM '.$this->getTableName('Member').' WHERE (is_active = 1 OR is_active IS NULL) AND id = ?', array($memberId));
  }

  protected function getInactiveMemberIds()
  {
    if (null !== $this->inactiveMemberIds)
    {
      return $this->inactiveMemberIds;
    }

    $results = array();
    $stmt =  $this->executeQuery('SELECT id FROM '.$this->getTableName('Member').' WHERE is_active = 0');
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
    $stmt = $this->executeQuery('SELECT member_id_to FROM '.$this->getTableName('MemberRelationship').' WHERE member_id_from = ? AND is_friend = 1', array($memberId));
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
    $memberConfig = $this->fetchRow('SELECT value FROM '.$this->getTableName('MemberConfig')." WHERE name = 'pc_address' AND member_id = ?", array($memberId));
    if ($memberConfig)
    {
      return $memberConfig['value'];
    }

    return false;
  }

  protected function getMemberMobileEmailAddress($memberId)
  {
    $memberConfig = $this->fetchRow('SELECT value FROM '.$this->getTableName('MemberConfig')." WHERE name = 'mobile_address' AND member_id = ?", array($memberId));
    if ($memberConfig)
    {
      return $memberConfig['value'];
    }

    return false;
  }

  protected function getTwigTemplate($env, $templateName, $isSignature = true)
  {
    $template = $this->getMailTemplate($env, $templateName, true);
    if ($isSignature)
    {
      $signature = $this->getMailTemplate($env, 'signature');
      $template['template'] = $template['template']."\n".$signature['template'];
    }

    if (!$template['title'] && $this->subject)
    {
      $template['title'] = $this->subject;
    }

    $twigEnvironment = new Twig_Environment(new Twig_Loader_String());

    return array(
      $twigEnvironment->loadTemplate($template['title']),
      $twigEnvironment->loadTemplate($template['template'])
    );
  }

  protected function getMailTemplate($env, $templateName, $require = false)
  {
    // First, load template from DB.
    $notificationMail = $this->fetchRow('SELECT id FROM '.$this->getTableName('NotificationMail').' WHERE name = ?', array($env.'_'.$templateName));

    if ($notificationMail)
    {
      $notificationMailTrans = $this->fetchRow("SELECT title, template FROM ".$this->getTableName('NotificationMailTranslation')
        ." WHERE id = ? AND lang = 'ja_JP'", array($notificationMail['id']));
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

    try
    {
      $mailer->send($this->transport);
    }
    catch (Zend_Mail_Protocol_Exception $e)
    {
      if (sfConfig::get('sf_logging_enabled', false))
      {
        sfContext::getInstance()->getLogger()->err('[opLiteMailTaskPlugin] zend mail protocol exception: '.$e->getMessage());
      }

      throw new sfException('zend mail protocol exception: '.$e->getMessage());
    }
  }
}
