<?php
/**
 * @author gareth.evans
 */
namespace Qubes\Defero\Applications\Defero;

use Cubex\Core\Application\Application;
use Cubex\Data\Ephemeral\ExpiringEphemeralCache;
use Cubex\Foundation\Config\Config;
use Cubex\Foundation\Config\ConfigGroup;
use Cubex\Foundation\Container;
use Cubex\Queue\StdQueue;
use Qubes\Defero\Components\Campaign\Enums\SendType;
use Qubes\Defero\Components\Campaign\Mappers\Campaign;
use Qubes\Defero\Components\Campaign\Mappers\MailStatistic;
use Qubes\Defero\Components\Campaign\Mappers\SentEmailLog;
use Qubes\Defero\Components\Contact\Mappers\Contact;
use Qubes\Defero\Transport\ProcessDefinition;
use Qubes\Defero\Transport\ProcessMessage;
use Themed\Sidekick\SidekickTheme;

class Defero extends Application
{
  public function name()
  {
    return "Defero";
  }

  public function description()
  {
    return "Mailer setup and configuration";
  }

  protected function _configure()
  {
    parent::_configure();
    $this->_listen('Qubes\Defero\Applications\Defero', $this->getConfig());
  }

  public function getTheme()
  {
    return new SidekickTheme();
  }

  public function defaultController()
  {
    return new Controllers\DeferoController();
  }

  public function getRoutes()
  {
    $base = '\Qubes\Defero\Applications\Defero\Controllers\\';
    return [
      "/campaigns/"     => [
        ":id@num/message/(.*)"     => $base . 'CampaignMessageController',
        ":id@num/source/(.*)"      => $base . 'CampaignSourceController',
        ":cid@num/processors/(.*)" => $base . 'CampaignProcessorsController',
        ":id@num/contacts/(.*)"    => $base . 'CampaignContactsController',
        "(.*)"                     => $base . 'CampaignsController',
      ],
      "/contacts/(.*)"  => $base . 'ContactsController',
      "/typeahead/(.*)" => $base . 'TypeAheadController',
      "/search/(.*)"    => $base . 'SearchController',
      "/wizard/(.*)"    => $base . 'WizardController',
      "/stats/(.*)"     => $base . 'StatsController',
    ];
  }

  /**
   * @param int        $campaignId
   * @param int        $startTime
   * @param int        $startId
   * @param int        $endId
   * @param null|array $additionalData
   *
   * @return bool
   * @throws \Exception
   */
  public static function pushCampaign(
    $campaignId, $startTime = null, $startId = null, $endId = null,
    $additionalData = null
  )
  {
    if($startTime === null)
    {
      $startTime = time();
      $startTime -= $startTime % 60;
    }
    $campaign = new Campaign($campaignId);
    $campaign->reload();
    if(!$campaign->processors)
    {
      throw new \Exception('Cannot queue a Campaign with no Processors');
    }

    $lastTime = $campaign->lastSent;
    if($lastTime != $startTime)
    {
      $campaign->lastSent = $startTime;
      $campaign->saveChanges();

      $message = new ProcessMessage();
      $message->setData('campaignId', $campaignId);
      $message->setData('startedAt', $startTime);
      $message->setData('lastSent', $lastTime);
      $message->setData('startId', $startId);
      $message->setData('endId', $endId);
      if($additionalData)
      {
        $message->setData('additionalData', $additionalData);
      }

      \Queue::setDefaultQueueProvider("campaignqueue");
      \Queue::push(new StdQueue('defero_campaigns'), serialize($message));
      \Log::info('Queued Campaign ' . $campaignId);
      return true;
    }
    \Log::info(
      'Campaign ' . $campaignId . ' already queued'
      . ' ~ ' . $lastTime . ' ' . $startTime
    );
    return false;
  }

  /**
   * @param int   $campaignId
   * @param array $data
   *
   * @return bool
   */
  public static function pushMessage($campaignId, $data)
  {
    return self::pushMessageBatch($campaignId, [$data]);
  }

  /**
   * @param int     $campaignId
   * @param array[] $batch
   *
   * @return bool
   * @throws \Exception
   */
  public static function pushMessageBatch($campaignId, array $batch)
  {
    if(!$batch)
    {
      return false;
    }

    $cacheId = 'DeferoQueueCampaign' . $campaignId;
    /**
     * @var Campaign $campaign
     * @var Contact  $contact
     */
    $campaign = ExpiringEphemeralCache::getCache($cacheId, __CLASS__);
    if($campaign === null)
    {
      $campaign = new Campaign($campaignId);
      $campaign->reload();
      ExpiringEphemeralCache::storeCache($cacheId, $campaign, __CLASS__, 60);
    }
    if(!$campaign || !$campaign->exists())
    {
      throw new \Exception('Campaign does not exist');
    }
    $campaignId        = $campaign->id();
    $processorsCacheId = $cacheId . ':processors';
    $processors        = ExpiringEphemeralCache::getCache(
      $processorsCacheId,
      __CLASS__
    );
    if($processors === null)
    {
      $processors       = [];
      $processorsConfig = Container::get(Container::CONFIG)->get('processors');
      if($campaign->processors)
      {
        foreach($campaign->processors as $processorData)
        {
          $config = new Config();
          $config->hydrate($processorData);

          $configGroup = new ConfigGroup();
          $configGroup->addConfig("process", $config);
          $process = new ProcessDefinition();
          $process->setProcessClass(
            $processorsConfig->getStr($processorData->processorType)
          );
          $process->setQueueName("defero");
          $process->setQueueService("queue");
          $process->configure($configGroup);
          $processors[] = $process;
        }
      }
      else
      {
        throw new \Exception(
          "Cannot queue campaign No default processors found."
        );
      }

      ExpiringEphemeralCache::storeCache(
        $processorsCacheId,
        $processors,
        __CLASS__,
        60
      );
    }

    $blacklistDomains = [];
    foreach(Container::config()->get('blacklist')->getArr('domains', []) as $d)
    {
      $blacklistDomains[] = preg_quote($d, '/');
    }
    $blacklistRegex = '/(' . implode('|', $blacklistDomains) . ')$/i';

    //grab all user_ids from $batch, check SentEmailLog
    $logKeys    = [];
    $keyedBatch = []; //use this to recover $batch
    $dedupe     = true;
    foreach($batch as $data)
    {
      if(isset($data['user_id']))
      {
        $logKeys[]                    = $data['user_id'] . '-' . $campaignId;
        $keyedBatch[$data['user_id']] = $data;
      }

      if(isset($data['dedupe']))
      {
        $dedupe = false;
      }
    }

    if($logKeys && $dedupe)
    {
      try
      {
        //check if we sent this campaign to users today or the previous day
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today     = date('Y-m-d');
        $result    = SentEmailLog::cf()->multiGet(
          $logKeys,
          [$yesterday, $today]
        );

        foreach($result as $key => $column)
        {
          if(is_array($column)
            && (isset($column[$yesterday]) || isset($column[$today]))
          )
          {
            list($userId, $cId) = explode('-', $key);
            //remove user from keyedBatch because they have already
            // received this campaign
            unset($keyedBatch[$userId]);
            \Log::info(
              "Skipping user because they already got this campaign: [user_id:"
              . $data['user_id'] . ', campaign_id: ' . $campaignId . "]"
            );
          }
        }

        $batch = array_values($keyedBatch);
      }
      catch(\Exception $e)
      {
        \Log::error("Email Deduping Failed: " . $e->getMessage());
      }
    }

    $messages = [];
    foreach($batch as $data)
    {
      $data = array_change_key_case($data);

      // check blacklist
      if($blacklistDomains && preg_match($blacklistRegex, $data['email']))
      {
        continue;
      }

      // move language here.
      $userLanguage = $data['language'] = !empty($data['language']) ?
        $data['language'] : 'en';

      $active = isset($data['campaignactive']) ?
        $data['campaignactive'] : $campaign->active;

      $message = new ProcessMessage();
      $message->setData('campaignId', $campaignId);
      $message->setData('campaignActive', $active);
      if(!$active)
      {
        $message->setData('emailService', 'email_test');
      }
      elseif($campaign->emailService)
      {
        $message->setData('emailService', $campaign->emailService);
      }
      else
      {
        $message->setData('emailService', 'email');
      }

      if($campaign->replyTo)
      {
        $message->setData('replyTo', $campaign->replyTo);
      }

      $message->setData('mailerTracking', $campaign->trackingType);
      $message->setData('data', $data);

      $languageCacheId = $cacheId . ':language:' . $userLanguage;
      $msg             = ExpiringEphemeralCache::getCache(
        $languageCacheId,
        __CLASS__
      );
      if($msg === null)
      {
        $msg = $campaign->message();
        $msg->setLanguage($userLanguage);
        $msg->reload();

        if($userLanguage !== 'en'
          && (!$msg->active
            || !$msg->subject
            || ($campaign->sendType != SendType::PLAIN_TEXT
              && !$msg->htmlContent)
            || ($campaign->sendType != SendType::HTML_ONLY
              && !$msg->plainText)
          )
        )
        {
          //for non eng if html and plain but no html we shouldn't default to english
          if($campaign->sendType == SendType::HTML_AND_PLAIN && !$msg->htmlContent && $msg->plainText)
          {
          }
          else
          {
            $msg->setLanguage('en');
            $msg->reload();
          }
        }

        ExpiringEphemeralCache::storeCache(
          $languageCacheId,
          $msg,
          __CLASS__,
          60
        );
      }

      $contactId      = $msg->contactId ?: $campaign->contactId;
      $contactCacheId = $cacheId . ':contact:' . $contactId;
      $contact        = ExpiringEphemeralCache::getCache(
        $contactCacheId,
        __CLASS__
      );
      if($contact === null)
      {
        $contact = new Contact($contactId);
        ExpiringEphemeralCache::storeCache(
          $contactCacheId,
          $contact,
          __CLASS__,
          60
        );
      }
      $data['signature'] = $contact->signature;

      $message->setData(
        'senderName',
        self::replaceData($contact->name, $data)
      );
      $message->setData(
        'senderEmail',
        self::replaceData($contact->email, $data)
      );
      $message->setData(
        'returnPath',
        self::replaceData($contact->returnPath, $data)
      );
      $message->setData(
        'sendType',
        self::replaceData($campaign->sendType, $data)
      );
      $message->setData(
        'subject',
        self::replaceData($msg->subject, $data)
      );
      $message->setData(
        'plainText',
        self::replaceData($msg->plainText, $data)
      );
      $message->setData(
        'htmlContent',
        self::replaceData($msg->htmlContent, $data, true)
      );

      foreach($processors as $process)
      {
        $message->addProcess($process);
      }
      $messages[] = serialize($message);
    }

    // queue
    \Queue::setDefaultQueueProvider("messagequeue");

    // prioritize
    if($campaign->active)
    {
      $priority = (int)$campaign->priority;
      $priority = ($priority < 0) ? 1 : $priority;
    }
    else
    {
      $priority = 99;
    }
    $queueName = 'mailer.defero_messages_priority_' . $priority;

    \Queue::pushBatch(new StdQueue($queueName), $messages);

    // stats
    try
    {
      $hour = time();
      $hour -= $hour % 3600;
      $statsCf = MailStatistic::cf();
      $statsCf->increment($campaignId, $hour . '|queued', count($messages));
    }
    catch(\Exception $e)
    {
      \Log::error(
        'Error writing stats for campaign ' . $campaignId . ' : '
        . $e->getMessage()
      );
    }

    \Log::info(
      'Queued ' . count($messages) . ' messages for Campaign ' . $campaignId
    );
    return true;
  }

  /**
   * @param string $reference
   *
   * @return null
   * @throws \Exception
   */
  public static function getCampaignIdFromReference($reference)
  {
    if(!is_numeric($reference))
    {
      $campaign = Campaign::collection()->loadOneWhere(
        '%C = %s',
        'reference',
        $reference
      );
      if(!$campaign)
      {
        throw new \Exception('Reference does not match any campaigns');
      }
      $reference = $campaign->id();
    }
    return $reference;
  }

  public static function replaceData($text, $data, $html = false)
  {
    $text = self::_replaceConditionals($text, $data, $html);

    foreach($data as $varName => $value)
    {
      if($html)
      {
        $value = mb_convert_encoding($value, 'HTML-ENTITIES', 'utf8');
        $value = nl2br($value);
      }
      $text = str_ireplace('{!' . $varName . '}', $value, $text);
    }

    return $text;
  }

  /**
   * Process conditionals in the format {?VARNAME|otherValue}.
   *  - If $data['VARNAME'] is set then use it, otherwise use the "otherValue"
   *
   * @param      $text
   * @param      $data
   * @param bool $html
   *
   * @return string
   */
  private static function _replaceConditionals($text, $data, $html = false)
  {
    $found = true;
    while($found)
    {
      $found = false;
      if(preg_match_all('/{([^{}]*|(?R))*}/', $text, $matches))
      {
        foreach($matches[0] as $expression)
        {
          if(preg_match('/^{\?([^|]*)\|(.*)}$/', $expression, $expMatches))
          {
            $found = true;

            $varName     = strtolower($expMatches[1]);
            $alternative = $expMatches[2];

            $value = empty($data[$varName]) ? $alternative : $data[$varName];
            if($html)
            {
              $value = mb_convert_encoding($value, 'HTML-ENTITIES', 'utf8');
              $value = nl2br($value);
            }
            $text = str_replace($expression, $value, $text);
          }
        }
      }
      $matches = array();
    }
    return $text;
  }
}
