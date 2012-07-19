<?php
/**
 * Artax Notifier Class File
 * 
 * @category    Artax
 * @package     Events
 * @author      Daniel Lowrey <rdlowrey@gmail.com>
 * @license     All code subject to the terms of the LICENSE file in the project root
 * @version     ${project.version}
 */
namespace Artax\Events;

use InvalidArgumentException,
    ArrayAccess,
    Traversable,
    StdClass,
    Artax\Injection\Injector,
    Artax\Injection\ProviderDefinitionException;

/**
 * A central transit hub for application event broadcasting
 * 
 * For advanced Notifier usage including lazy-loading class event listeners,
 * check out the relevant wiki entry:
 * 
 * https://github.com/rdlowrey/Artax/wiki/Event-Management
 * 
 * @category    Artax
 * @package     Core
 * @author      Daniel Lowrey <rdlowrey@gmail.com>
 */
class Notifier implements Mediator {
    
    /**
     * @var array
     */
    private $listeners = array();
    
    /**
     * @var Injector
     */
    private $injector;
    
    /**
     * @var array
     */
    private $eventBroadcastCounts = array();
    
    /**
     * @var array
     */
    private $listenerInvocationCounts = array();
    
    /**
     * @var array
     */
    private $lastQueueDelta = array();
    
    /**
     * @param Injector $injector
     * @return void
     */
    public function __construct(Injector $injector) {
        $this->injector = $injector;
    }
    
    /**
     * Pushes multiple listeners onto the relevant queues
     * 
     * @param mixed $iter The variable to loop through: array|Traversable|StdClass
     * @return void
     * @throws InvalidArgumentException
     */
    public function pushAll($iter) {
        if (!($iter instanceof StdClass || $iter instanceof Traversable || is_array($iter))) {
            throw new InvalidArgumentException(
                'Argument 1 passed to '.get_class($this).'::pushAll must be an '
                .'array, StdClass or Traversable object'
            );
        }
        
        foreach ($iter as $event => $listener) {
            $this->push($event, $listener);
        }
    }
    
    /**
     * Connect a listener to the end of the specified event queue
     * 
     * To enable listener lazy-loading, all string listeners are assumed to be 
     * class names and will be instantiated using the dependency provider. This
     * means that global function names and static class methods (in string form)
     * cannot be attached.
     * 
     * @param string $eventName Event identifier name to listen for
     * @param mixed  $listener  A valid callable or string class name
     * 
     * @return int Returns the new number of listeners queued for the specified event
     * 
     * @throws LogicException On non-callable, non-string $listener parameter
     */
    public function push($eventName, $listener) {
        if (is_string($listener) || is_callable($listener)) {
            
            $this->setLastQueueDelta($eventName, 'push');
            $this->listeners[$eventName][] = $listener;
            
        } elseif ($listener instanceof Traversable || is_array($listener)) {
            
            foreach ($listener as $listenerElement) {
                $this->push($eventName, $listenerElement);
            }
            
        } else {
            
            throw new InvalidArgumentException(
                'Argument 2 passed to ' . get_class($this) .'::push must be a valid ' . 
                'callable or string class name'
            );
            
        }
        
        return count($this->listeners[$eventName]);
    }
    
    /**
     * @param string $eventName
     * @param string $action
     */
    private function setLastQueueDelta($eventName, $action) {
        $this->lastQueueDelta = array($eventName, $action);
        $this->notify('__mediator.delta', $this);
    }
    
    /**
     * Attach an event listener to the front of the event queue
     * 
     * @param string $eventName
     * @param mixed  $listener  A valid callable or string class name
     * @return int Returns the new number of listeners in the event queue
     * @throws InvalidArgumentException
     */
    public function unshift($eventName, $listener) {
        if (!(is_string($listener) || is_callable($listener))) {
            throw new InvalidArgumentException(
                'Argument 2 passed to ' . get_class($this) .'::unshift must be a valid ' . 
                'callable or string class name'
            );
        }
        
        $this->setLastQueueDelta($eventName, 'unshift');
        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = array();
        }
        array_unshift($this->listeners[$eventName], $listener);
        
        return count($this->listeners[$eventName]);
    }
    
    /**
     * Remove a listener from the front of the specified event queue
     * 
     * @param string $eventName
     * @return mixed Returns shifted listener or null if no listeners assigned
     */
    public function shift($eventName) {
        $this->setLastQueueDelta($eventName, 'shift');
        
        if (isset($this->listeners[$eventName])) {
            return array_shift($this->listeners[$eventName]);
        } else {
            return null;
        }
    }
    
    /**
     * Remove the last listener from the end of the specified event queue
     * 
     * @param string $eventName
     * @return mixed Returns popped listener or null if no listeners assigned
     */
    public function pop($eventName) {
        $this->setLastQueueDelta($eventName, 'pop');
        
        if (isset($this->listeners[$eventName])) {
            return array_pop($this->listeners[$eventName]);
        } else {
            return null;
        }
    }
    
    /**
     * Clear all listeners for the specified event
     * 
     * @param string $eventName Event name
     * @return void
     */
    public function clear($eventName) {
        $this->setLastQueueDelta($eventName, 'clear');
        unset($this->listeners[$eventName]);
    }
    
    /**
     * Notify listeners that an event has occurred
     * 
     * @param string $eventName
     * @return int Returns a count of listeners invoked for this event broadcast
     * @throws BadListenerException
     */
    public function notify($eventName) {
        $this->incrementEventBroadcastCount($eventName);
        
        $args = func_get_args();
        array_shift($args);
        
        $listenerCount = $this->count($eventName);
        $invocationCount = 0;
        
        for ($queuePos = 0; $queuePos < $listenerCount; $queuePos++) {
        
            $listener = $this->getCallableListenerFromQueue($eventName, $queuePos);
            
            $this->incrementListenerInvocationCount($eventName);
            
            $result = $args ? call_user_func_array($listener, $args) : call_user_func($listener);
            
            ++$invocationCount;
            
            if (false === $result) {
                break;
            }
        }
        
        return $invocationCount;
    }
    
    /**
     * @param string $eventName
     * @return void
     */
    private function incrementEventBroadcastCount($eventName) {
        if (!isset($this->eventBroadcastCounts[$eventName])) {
            $this->eventBroadcastCounts[$eventName] = 0;
        }
        ++$this->eventBroadcastCounts[$eventName];
    }
    
    /**
     * @param string $eventName
     * @return void
     */
    private function incrementListenerInvocationCount($eventName) {
        if (!isset($this->listenerInvocationCounts[$eventName])) {
            $this->listenerInvocationCounts[$eventName] = 0;
        }
        ++$this->listenerInvocationCounts[$eventName];
    }
    
    /**
     * Retrieve a list of all listeners queued for the specified event
     * 
     * @param string $eventName The event for which listeners should be returned
     * @return array Returns an array of queued listeners for the specified event
     */
    public function all($eventName) {
        return $this->count($eventName) ? $this->listeners[$eventName] : array();
    }
    
    /**
     * Retrieve the first event listener in the queue for the specified event
     * 
     * @param string $eventName
     * @return mixed Returns the first event listener in the queue or null if none assigned
     */
    public function first($eventName) {
        return $this->count($eventName) ? $this->listeners[$eventName][0] : null;
    }
    
    /**
     * Retrieve the last event listener in the queue for the specified event
     * 
     * @param string $eventName
     * @return mixed Returns the last event listener in the queue or null if none assigned
     */
    public function last($eventName) {
        return ($count = $this->count($eventName)) ? $this->listeners[$eventName][$c-1] : null;
    }
    
    /**
     * Retrieve a list of all listened-for events
     * 
     * @return array Returns an array of listened-for events
     */
    public function keys() {
        return array_keys($this->listeners);
    }
    
    /**
     * Retrieve a count of all listeners in the queue for a specific event
     * 
     * @param string $eventName Event identifier name
     * @return int Returns a count of queued listeners for the specified event
     */
    public function count($eventName) {
        return isset($this->listeners[$eventName]) ? count($this->listeners[$eventName]) : 0;
    }
    
    /**
     * Get the total number of listeners that have been invoked for an event
     * 
     * @param string $eventName
     * @return int Returns the count of all invocations for the given event.
     */
    public function countInvocations($eventName) {
        if (isset($this->listenerInvocationCounts[$eventName])) {
            return $this->listenerInvocationCounts[$eventName];
        } else {
            return 0;
        }
    }
    
    /**
     * Get the total number of times an event has been broadcast/notified
     * 
     * @param string $eventName
     * @return int
     */
    public function countNotifications($eventName) {
        if (isset($this->eventBroadcastCounts[$eventName])) {
            return $this->eventBroadcastCounts[$eventName];
        } else {
            return 0;
        }
    }
    
    /**
     * @return array
     */
    public function getLastQueueDelta() {
        return $this->lastQueueDelta;
    }
    
    /**
     * @param string $eventName
     * @param int $queuePos
     * @return mixed Returns a callable event listener
     * @throws BadListenerException
     */
    private function getCallableListenerFromQueue($eventName, $queuePos) {
        if (is_string($this->listeners[$eventName][$queuePos])) {
            
            $className = $this->listeners[$eventName][$queuePos];
            
            try {
                
                $listener = $this->injector->make($className);
                
            } catch (ProviderDefinitionException $e) {
                
                throw new BadListenerException(
                    "Invalid class listener ($className) specified in the `$eventName` " .
                    "queue at position $queuePos. Auto-instantiation failed with the " .
                    'following message: ' . $e->getMessage()
                );
            }
            
            if (!is_callable($listener)) {
                throw new BadListenerException(
                    "Invalid listener specified in the `$eventName` queue at position " .
                    "$queuePos: object of type ".get_class($listener).' is not callable'
                );
            }
            
            return $listener;
            
        } else {
            
            return $this->listeners[$eventName][$queuePos];
            
        }
    }
}