<?php
/**
 * @author  brooke.bryan
 */

namespace Qubes\Defero\Cli\Campaign;

use Cubex\Cli\CliCommand;
use Cubex\Cli\PidFile;
use Cubex\Cli\Shell;
use Cubex\Facade\Queue;
use Cubex\Figlet\Figlet;
use Cubex\Log\Log;
use Cubex\Queue\Provider\Database\DatabaseQueue;
use Cubex\Queue\StdQueue;
use Psr\Log\LogLevel;
use Qubes\Defero\Components\Campaign\Consumers\CampaignConsumer;

class ProcessQueue extends CliCommand
{
  protected $_echoLevel = LogLevel::INFO;
  protected $_defaultLogLevel = LogLevel::DEBUG;

  /**
   * Queue Provider Service to read messages from
   *
   * @valuerequired
   */
  public $queueService = 'messagequeue';

  /**
   * Queue Name to pull messages from
   *
   * @valuerequired
   */
  public $queueName = 'mailer.defero_messages_priority_';

  /**
   * @valuerequired
   */
  public $instanceName;

  /**
   * @valuerequired
   * @required
   * @validate int
   */
  public $priority;

  private $_pidFile;

  /**
   * @return int
   */
  public function execute()
  {
    $this->_logger->setInstanceName($this->instanceName);
    $this->_pidFile = new PidFile("", $this->instanceName);

    echo Shell::colourText(
      (new Figlet("speed"))->render("Defero"),
      Shell::COLOUR_FOREGROUND_GREEN
    );
    echo "\n";

    Log::debug("Setting Default Queue Provider to " . $this->queueService);
    Queue::setDefaultQueueProvider($this->queueService);

    $queue = Queue::getAccessor();
    if($queue instanceof DatabaseQueue)
    {
      $instance = gethostname();
      if($this->instanceName)
      {
        $instance .= ':' . $this->instanceName;
      }
      $queue->setOwnKey($instance);
    }

    $priority = (int)$this->priority;
    if(in_array($priority, [1, 5, 10]))
    {
      $this->queueName .= $priority;
    }
    else
    {
      throw new \Exception("Invalid priority. Supported values: 1 , 5, 10");
    }

    Log::info("Starting to consume queue " . $this->queueName);
    $queue->consume(
      new StdQueue($this->queueName),
      new CampaignConsumer()
    );

    Log::info("Exiting Defero Processor");
  }
}
