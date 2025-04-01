<?php

declare(strict_types=1);

namespace Hongyi\Support;

class Pipeline
{
    protected $container;

    protected mixed $passable;

    protected mixed $pipes;

    protected string $method = 'handle';

    protected $finally;

    /**
     * 设置通过管道传输的对象
     *
     * @param mixed $passable
     * @return $this
     */
    public function send(mixed $passable): static
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * 设置管道阵列(数组)
     *
     * @param mixed $pipes
     * @return $this
     */
    public function through(mixed $pipes): static
    {
        $this->pipes = is_array($pipes) ? $pipes : func_get_args();

        return $this;
    }

    /**
     * 将更多管道追加到现有管道阵列中
     *
     * @param mixed $pipes
     * @return $this
     */
    public function pipe(mixed $pipes): static
    {
        array_push($this->pipes, ...(is_array($pipes) ? $pipes : func_get_args()));

        return $this;
    }

    /**
     * 设置管道上调用的方法的名称
     *
     * @param string $method
     * @return $this
     */
    public function via(string $method): static
    {
        $this->method = $method;

        return $this;
    }

    /**
     * 运行带有最终目的地回调的管道流水线
     *
     * @param \Closure $destination
     * @return mixed
     */
    public function then(\Closure $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes()), $this->carry(), $this->prepareDestination($destination)
        );

        try {
            return $pipeline($this->passable);
        } finally {
            if ($this->finally) {
                ($this->finally)($this->passable);
            }
        }
    }

    /**
     * 普通运行管道流水线「无特殊处理」，并返回结果
     *
     * @return mixed
     */
    public function thenReturn(): mixed
    {
        return $this->then(function ($passable) {
            return $passable;
        });
    }

    /**
     * 设置管道流水线全部执行结束后的最终回调，无论管道流水线的执行结果如何
     *
     * @param \Closure $callback
     * @return $this
     */
    public function finally(\Closure $callback): static
    {
        $this->finally = $callback;

        return $this;
    }

    /**
     * 获取已经配置的管道阵列(数组)
     *
     * @return mixed
     */
    protected function pipes(): mixed
    {
        return $this->pipes;
    }

    /**
     * 获取管道流水线执行的最后一步，并在回调中进行注入
     * 这里放弃了try-catch，继续向上抛出对应的异常，以方便上游调用时进行捕获
     *
     * @param \Closure $destination
     * @return \Closure
     */
    protected function prepareDestination(\Closure $destination): \Closure
    {
        return function ($passable) use ($destination) {
            return $destination($passable);
        };
    }

    /**
     * 获取管道流水线执行的每一步，并根据当前传入的管道类型，进行执行注入或直接调用
     * 这里放弃了try-catch，继续向上抛出对应的异常，以方便上游调用时进行捕获
     *
     * @return \Closure
     */
    protected function carry(): \Closure
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                if (is_callable($pipe)) {
                    // 如果管道是可调用的，那么将直接调用它，否则将从依赖容器中解析出管道，然后使用适当的方法和参数调用它，并将结果返回出来
                    return $pipe($passable, $stack);
                } elseif (!is_object($pipe)) {
                    [$name, $parameters] = $this->parsePipeString($pipe);
                    // 如果管道是字符串，将解析该字符串，并从依赖注入容器中解析出类，然后，创建一个可调用的函数，并执行管道函数，输入所需的参数
//                    $pipe = $this->getContainer()->make($name); // laravel
//                    $pipe = $this->getContainer()->get($name);  // hyperf
                    try {
                        $pipe = (new \ReflectionClass($pipe))->newInstance($name);
                    } catch (\ReflectionException $e) {
                        $pipe = $pipe;
                    }

                    $parameters = array_merge([$passable, $stack], $parameters);
                } else {
                    // 如果管道已经是一个对象，只需创建一个可调用对象，并将其原封不动的传递给管道，由于给定的对象已经是一个完全实例化的对象，因此无需进行任何额外的解析和格式化
                    $parameters = [$passable, $stack];
                }

                $carry = method_exists($pipe, $this->method)
                    ? $pipe->{$this->method}(...$parameters)
                    : $pipe(...$parameters);

                return $this->handleCarry($carry);
            };
        };
    }

    /**
     * 解析完整的管道名称字符串，获取名称和参数
     *
     * @param $pipe
     * @return array
     */
    protected function parsePipeString($pipe): array
    {
        [$name, $parameters] = array_pad(explode(':', $pipe, 2), 2, null);

        if (!is_null($parameters)) {
            $parameters = explode(',', $parameters);
        } else {
            $parameters = [];
        }

        return [$name, $parameters];
    }

    /**
     * 处理每个管道流水线的返回值，然后再将其传递给下一个管道流水线
     *
     * @param $carry
     * @return mixed
     */
    protected function handleCarry($carry): mixed
    {
        return $carry;
    }
}