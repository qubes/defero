<?php
/**
 * @author  brooke.bryan
 */

namespace Qubes\Defero\Components\Campaign\Consumers;

use Cubex\Foundation\Config\IConfigurable;
use Cubex\Log\Log;
use Cubex\Queue\IBatchQueueConsumer;
use Cubex\Queue\IQueue;
use Cubex\Queue\StdQueue;
use Qubes\Defero\Components\Campaign\Rules\Delivery\IDeliveryRule;
use Qubes\Defero\Transport\IRule;
use Qubes\Defero\Transport\IProcess;
use Qubes\Defero\Transport\IProcessDefinition;
use Qubes\Defero\Transport\IProcessMessage;

class CampaignConsumer implements IBatchQueueConsumer
{
  /**
   * @var IProcessMessage
   */
  protected $_batch;
  protected $_queueDelays = [];

  public function process(IQueue $queue, $message, $taskID = null)
  {
    if(is_scalar($message))
    {
      $message = unserialize($message);
    }
    if($message instanceof IProcessMessage)
    {
      $this->_batch[$taskID] = $message;
    }
    return true;
  }

  public function runBatch()
  {
    $results = [];
    /**
     * @var $message IProcessMessage
     */
    foreach($this->_batch as $taskId => $message)
    {
      try
      {
        foreach($message->getProcessQueue() as $currentProcess)
        {
          if(!$this->runProcess($message, $currentProcess, $taskId))
          {
            break;
          }
        }
      }
      catch(\Exception $e)
      {
        Log::error($e->getMessage());
      }

      $results[$taskId] = true;
    }
    $this->_batch = [];
    return $results;
  }

  public function getBatchSize()
  {
    return 25;
  }

  public function runProcess(
    IProcessMessage $message, IProcessDefinition $process, $taskId
  )
  {
    $this->_queueDelays[$taskId] = 0;
    $class                       = $process->getProcessClass();
    if(class_exists($class))
    {
      $ruleClass = 'Qubes\Defero\Transport\IMessageProcessor';
      /*
       * replaced below with faster implementation:
       * if(in_array($ruleClass, class_implements($class))))
       */
      if(is_subclass_of($class, $ruleClass))
      {
        $proc = new $class($message);
      }
      else
      {
        $proc = new $class();
      }

      if($proc instanceof IConfigurable)
      {
        $proc->configure($process->getConfig());
      }

      if($proc instanceof IRule)
      {
        if($proc->canProcess())
        {
          if($proc instanceof IDeliveryRule)
          {
            $this->_queueDelays[$taskId] = (int)$proc->getSendDelay();
            Log::debug(
              "Setting queue delay to " . $this->_queueDelays[$taskId]
            );
          }
          return true;
        }
        return false;
      }
      else if($proc instanceof IProcess)
      {
        return $proc->process();
      }
    }
    return false;
  }

  /**
   * Seconds to wait before re-attempting, false to exit
   *
   * @param int $waits amount of times script has waited
   *
   * @return mixed
   */
  public function waitTime($waits = 0)
  {
    return rand(10, 30);
  }

  /**
   * Time in seconds to treat queue locks as stale, false to never unlock
   *
   * @return bool|int
   */
  public function lockReleaseTime()
  {
    return 3600;
  }

  public function shutdown()
  {
    return true;
  }
}
