<?php
namespace Imi\Pool;

use Imi\App;
use Imi\Worker;
use Imi\Util\ArrayUtil;
use Imi\Bean\BeanFactory;
use Imi\Pool\Interfaces\IPool;
use Imi\Pool\Interfaces\IPoolResource;

abstract class BasePool implements IPool
{
    /**
     * 池子名称
     * @var string
     */
    protected $name;

    /**
     * 池子存储
     * @var \Imi\Pool\PoolItem[]
     */
    protected $pool = [];

    /**
     * 配置
     * @var \Imi\Pool\Interfaces\IPoolConfig
     */
    protected $config;

    /**
     * 资源配置
     * @var mixed
     */
    protected $resourceConfig;

    /**
     * 时间间隔定时器ID
     * @var int
     */
    protected $timerID;

    /**
     * 当前配置序号
     *
     * @var integer
     */
    protected $configIndex = -1;

    /**
     * 正在添加中的资源数量
     *
     * @var integer
     */
    protected $addingResources = 0;

    public function __construct(string $name, \Imi\Pool\Interfaces\IPoolConfig $config = null, $resourceConfig = null)
    {
        $this->name = $name;
        if(null !== $config)
        {
            $this->config = $config;
        }
        if(!is_array($resourceConfig) || ArrayUtil::isAssoc($resourceConfig))
        {
            $this->resourceConfig = [$resourceConfig];
        }
        else
        {
            $this->resourceConfig = $resourceConfig;
        }
    }

    public function __init()
    {
        if(is_array($this->config))
        {
            $this->config = BeanFactory::newInstance(PoolConfig::class, $this->config);
        }
    }

    /**
     * 获取池子名称
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取池子配置
     * @return \Imi\Pool\Interfaces\IPoolConfig
     */
    public function getConfig(): \Imi\Pool\Interfaces\IPoolConfig
    {
        return $this->config;
    }
    
    /**
     * 打开池子
     * @return void
     */
    public function open()
    {
        // 初始化队列
        $this->initQueue();
        // 填充最少资源数
        $this->fillMinResources();
        // 定时资源回收
        $this->stopAutoGC();
        $this->startAutoGC();
    }

    /**
     * 关闭池子，释放所有资源
     * @return void
     */
    public function close()
    {
        $this->stopAutoGC();
        foreach($this->pool as $item)
        {
            $item->getResource()->close();
        }
    }

    /**
     * 释放资源占用
     * @param \Imi\Pool\Interfaces\IPoolResource $resource
     * @return void
     */
    public function release(IPoolResource $resource)
    {
        $hash = $resource->hashCode();
        if(isset($this->pool[$hash]))
        {
            $this->pool[$hash]->release();
            $resource->reset();
            $this->push($resource);
        }
    }

    /**
     * 资源回收
     * @return void
     */
    public function gc()
    {
        $hasGC = false;
        $maxActiveTime = $this->config->getMaxActiveTime();
        $maxUsedTime = $this->config->getMaxUsedTime();
        foreach($this->pool as $key => $item)
        {
            if(
                (null !== $maxActiveTime && $item->isFree() && time() - $item->getCreateTime() >= $maxActiveTime) // 最大存活时间
                || (null !== $maxUsedTime && $item->getLastReleaseTime() < $item->getLastUseTime() && time() - $item->getLastUseTime() >= $maxUsedTime) // 每次获取资源最长使用时间
                )
            {
                $item->getResource()->close();
                unset($this->pool[$key]);
                $hasGC = true;
            }
        }
        if($hasGC)
        {
            $this->fillMinResources();
            $this->buildQueue();
        }
    }

    /**
     * 填充最少资源数量
     * @return void
     */
    public function fillMinResources()
    {
        while($this->config->getMinResources() - $this->getCount() > 0)
        {
            $this->addResource();
        }
    }

    /**
     * 添加资源
     * @return IPoolResource
     */
    protected function addResource()
    {
        try {
            ++$this->addingResources;
            $resource = $this->createResource();
            $resource->open();

            $hash = $resource->hashCode();
            $this->pool[$hash] = new PoolItem($resource);

            $this->push($resource);

            return $resource;
        } finally {
            --$this->addingResources;
        }
    }

    /**
     * 初始化队列
     * @return void
     */
    protected abstract function initQueue();

    /**
     * 建立队列
     * @return void
     */
    protected abstract function buildQueue();

    /**
     * 创建资源
     * @return \Imi\Pool\Interfaces\IPoolResource
     */
    protected abstract function createResource(): \Imi\Pool\Interfaces\IPoolResource;

    /**
     * 把资源加入队列
     * @param IPoolResource $resource
     * @return void
     */
    protected abstract function push(IPoolResource $resource);

    /**
     * 开始自动垃圾回收
     * @return void
     */
    public function startAutoGC()
    {
        if(null !== Worker::getWorkerID())
        {
            $this->__startAutoGC();
        }
    }

    /**
     * 开始自动垃圾回收
     * @return void
     */
    private function __startAutoGC()
    {
        $gcInterval = $this->config->getGCInterval();
        if(null !== $gcInterval)
        {
            $this->timerID = \swoole_timer_tick($gcInterval * 1000, [$this, 'gc']);
        }
    }

    /**
     * 停止自动垃圾回收
     * @return void
     */
    public function stopAutoGC()
    {
        if(null !== $this->timerID)
        {
            \swoole_timer_clear($this->timerID);
        }
    }

    /**
     * 获得资源配置
     * @return mixed
     */
    public function getResourceConfig()
    {
        return $this->resourceConfig;
    }

    /**
     * 获取当前池子中资源总数
     * @return int
     */
    public function getCount()
    {
        return count($this->pool) + $this->addingResources;
    }

    /**
     * 获取当前池子中正在使用的资源总数
     * @return int
     */
    public function getUsed()
    {
        return $this->getCount() - $this->getFree();
    }

    /**
     * 获取下一个资源配置
     *
     * @return void
     */
    protected function getNextResourceConfig()
    {
        switch($this->config->getResourceConfigMode())
        {
            case ResourceConfigMode::RANDOM:
                $index = mt_rand(0, count($this->resourceConfig) - 1);
                break;
            default:
                $maxIndex = count($this->resourceConfig) - 1;
                if(++$this->configIndex > $maxIndex)
                {
                    $this->configIndex = 0;
                }
                $index = $this->configIndex;
                break;
        }
        return $this->resourceConfig[$index];
    }
}