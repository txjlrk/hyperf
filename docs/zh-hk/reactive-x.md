# ReactiveX 集成

[hyperf/reactive-x](https://github.com/hyperf/reactive-x) 組件提供了 Swoole/Hyperf 環境下的 ReactiveX 集成。

## ReactiveX 的歷史

ReactiveX 是 Reactive Extensions 的縮寫，一般簡寫為 Rx，最初是 LINQ 的一個擴展，由微軟的架構師 Erik Meijer 領導的團隊開發，在 2012 年 11 月開源，Rx 是一個編程模型，目標是提供一致的編程接口，幫助開發者更方便的處理異步數據流，Rx 庫支持.NET、JavaScript 和 C ++，Rx 近幾年越來越流行了，現在已經支持幾乎全部的流行編程語言了，Rx 的大部分語言庫由 ReactiveX 這個組織負責維護，比較流行的有 RxJava/RxJS/Rx.NET，社區網站是 [reactivex.io](http://reactivex.io)。

## 什麼是 ReactiveX

微軟給的定義是，Rx 是一個函數庫，讓開發者可以利用可觀察序列和 LINQ 風格查詢操作符來編寫異步和基於事件的程序，使用 Rx，開發者可以用 Observables 表示異步數據流，用 LINQ 操作符查詢異步數據流， 用 Schedulers 參數化異步數據流的併發處理，Rx 可以這樣定義：Rx = Observables + LINQ + Schedulers。

[Reactivex.io](http://reactivex.io) 給的定義是，Rx 是一個使用可觀察數據流進行異步編程的編程接口，ReactiveX 結合了觀察者模式、迭代器模式和函數式編程的精華。

> 以上兩節摘自[RxDocs](https://github.com/mcxiaoke/RxDocs)。

## 使用前請考慮

### 正面

- 通過響應式編程的思考方式，可以將一些複雜異步問題化繁為簡。

- 如果您已經在其他語言有過響應式編程經驗(如 RxJS/RxJava)，本組件可以幫助您將這種經驗移植到 Hyperf 上。

- 儘管 Swoole 中推薦通過協程像編寫同步程序一樣編寫異步程序，但 Swoole 中仍然包含了大量事件，而處理事件正是 Rx 的強項。

- 如果您業務中包含流處理，如 WebSocket，gRPC streaming 等，Rx 也可以發揮重要作用。

### 負面

- 響應式編程的思維方式和傳統面向對象思維方式差異較大，需要開發者適應。

- Rx 只是提供了思維方式，並沒有額外的魔法。通過響應式編程能夠解決的問題通過傳統方式一樣能夠解決。

- RxPHP 並不是 Rx 家族中的佼佼者。

## 安裝

```bash
composer require hyper/reactive-x
```

## 封裝

下面我們結合示例來介紹本組件的一些封裝，並展示 Rx 的強大能力。全部示例可以在本組件 `src/Example` 下找到。

### Observable::fromEvent

`Observable::fromEvent` 將 PSR 標準事件轉為可觀察序列。

在 hyperf-skeleton 骨架包中默認提供了打印 SQL 語句的事件監聽，默認位置於 `app/Listener/DbQueryExecutedListener.php`。下面我們對這個監聽做一些優化：

1. 只打印超過 100ms 的 SQL 查詢。

2. 每個連接最多 1 秒打印 1 次，避免硬盤被問題程序刷爆。

如果沒有 ReactiveX，問題 1 還好説，而問題 2 應該就需要動一番腦筋了。而通過 ReactiveX，則可以通過下面的示例代碼的方式輕鬆解決這些需求：

```php
<?php

declare(strict_types=1);

namespace Hyperf\ReactiveX\Example;

use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Hyperf\Logger\LoggerFactory;
use Hyperf\ReactiveX\Observable;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Str;
use Psr\Container\ContainerInterface;

class SqlListener implements ListenerInterface
{
    private $logger;

    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(LoggerFactory::class)->get('sql');
    }

    public function listen(): array
    {
        return [
            BeforeWorkerStart::class,
        ];
    }

    public function process(object $event)
    {
        Observable::fromEvent(QueryExecuted::class)
            ->filter(
                function ($event) {
                    return $event->time > 100;
                }
            )
            ->groupBy(
                function ($event) {
                    return $event->connectionName;
                }
            )
            ->flatMap(
                function ($group) {
                    return $group->throttle(1000);
                }
            )
            ->map(
                function ($event) {
                    $sql = $event->sql;
                    if (! Arr::isAssoc($event->bindings)) {
                        foreach ($event->bindings as $key => $value) {
                            $sql = Str::replaceFirst('?', "'{$value}'", $sql);
                        }
                    }
                    return [$event->connectionName, $event->time, $sql];
                }
            )->subscribe(
                function ($message) {
                    $this->logger->info(sprintf('slow log: [%s] [%s] %s', ...$message));
                }
            );
    }
}
```

### Observable::fromChannel

將 Swoole 協程中的 Channel 轉為可觀察序列。

Swoole 協程中的 Channel 是讀寫一對一的。如果我們希望通過 Channel 來做多對多訂閲和發佈在 ReactiveX 下該怎麼做呢？

請參閲下面這個例子。

```php
<?php

declare(strict_types=1);

use Hyperf\ReactiveX\Observable;
use Swoole\Coroutine\Channel;

$chan = new Channel(1);
$pub = Observable::fromChannel($chan)->publish();

$pub->subscribe(function ($x) {
    echo 'First Subscription:' . $x . PHP_EOL;
});
$pub->subscribe(function ($x) {
    echo 'Second Subscription:' . $x . PHP_EOL;
});
$pub->connect();

$chan->push('hello');
$chan->push('world');

// First Subscription: hello
// Second Subscription: hello
// First Subscription: world
// Second Subscription: world
```

### Observable::fromCoroutine

創建一個或多個協程並將執行結果轉為可觀察序列。

我們現在讓兩個函數在併發協程中競爭，哪個先執行完畢的就返回哪個的結果。效果類似 JavaScript 中的 `Promise.race`。

```php
<?php

declare(strict_types=1);

use Hyperf\ReactiveX\Observable;
use Swoole\Coroutine\Channel;

$result = new Channel(1);
$o = Observable::fromCoroutine([function () {
    sleep(2);
    return 1;
}, function () {
    sleep(1);
    return 2;
}]);
$o->take(1)->subscribe(
    function ($x) use ($result) {
        $result->push($x);
    }
);
echo $result->pop(); // 2;
```

### Observable::fromHttpRoute

所有的 HTTP 請求其實也是事件驅動的。所以 HTTP 請求路由也可以用 ReactiveX 來接管。

> 由於我們要添加路由，所以務必要在 Server 啟動前執行，如在 `BootApplication` 事件監聽中。

假設我們有一個上傳路由，流量很大，需要在內存中緩衝，上傳十次以後再批量入庫。

```php
<?php

declare(strict_types=1);

namespace Hyperf\ReactiveX\Example;

use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\ReactiveX\Observable;
use Psr\Http\Message\RequestInterface;

class BatchSaveRoute implements ListenerInterface
{
    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    /**
     * @param QueryExecuted $event
     */
    public function process(object $event)
    {
        Observable::fromHttpRoute(['POST', 'PUT'], '/save')
            ->map(
                function (RequestInterface $request) {
                    return $request->getBody();
                }
            )
            ->bufferWithCount(10)
            ->subscribe(
                function (array $bodies) {
                    echo count($bodies); //10
                }
            );
    }
}
```

接管路由後如果需要控制返回的 Response，可以在 fromHttpRoute 中增加第三個參數，與正常路由寫法相同，如

```php
$observable = Observable::fromHttpRoute('GET', '/hello-hyperf', 'App\Controller\IndexController::hello');
```

此時 `Observable` 作用類似於中間件，獲取請求對象可觀察序列後會繼續傳遞請求對象給真正的 `Controller`。

### IpcSubject

Swoole 的進程間通訊也是事件驅動的。本組件在 RxPHP 提供的四種 [Subject](https://mcxiaoke.gitbooks.io/rxdocs/content/Subject.html) 基礎上額外提供了對應的跨進程 Subject 版本，可以用於在進程間共享信息。

例如，我們需要製作一個基於 WebSocket 的聊天室，需求如下：

1. 聊天室的消息需要在 `Worker 進程` 之間共享。

2. 用户第一次登錄時顯示最新的 5 條消息。

我們通過 `ReplaySubject` 的跨進程版本來實現。

```php
<?php

declare(strict_types=1);

namespace Hyperf\ReactiveX\Example;

use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\ReactiveX\Contract\BroadcasterInterface;
use Hyperf\ReactiveX\IpcSubject;
use Rx\Subject\ReplaySubject;
use Swoole\Http\Request;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;

class WebSocketController implements OnMessageInterface, OnOpenInterface, OnCloseInterface
{
    /**
     * @var IpcSubject
     */
    private $subject;

    private $subscriber = [];

    public function __construct(BroadcasterInterface $broadcaster)
    {
        $relaySubject = make(ReplaySubject::class, ['bufferSize' => 5]);
        // 第一個參數為原 RxPHP Subject 對象。
        // 第二個參數為廣播方式，默認為全進程廣播
        // 第三個參數為頻道 ID, 每個頻道只能收到相同頻道的消息。
        $this->subject = new IpcSubject($relaySubject, $broadcaster, 1);
    }

    public function onMessage(WebSocketServer $server, Frame $frame): void
    {
        $this->subject->onNext($frame->data);
    }

    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        $this->subscriber[$fd]->dispose();
    }

    public function onOpen(WebSocketServer $server, Request $request): void
    {
        $this->subscriber[$request->fd] = $this->subject->subscribe(function ($data) use ($server, $request) {
            $server->push($request->fd, $data);
        });
    }
}

```

為了方便使用，本組件利用 `IpcSubject` 封裝了一條 “消息總線” `MessageBusInterface`。只需要注入 `MessageBusInterface` 就可以收發全進程共享信息（包括自定義進程）。諸如配置中心一類的功能可以通過它來輕鬆實現。

```php
<?php
$bus = make(Hyperf\ReactiveX\MessageBusInterface::class);
// 全進程廣播信息
$bus->onNext('Hello Hyperf');
// 訂閲信息
$bus->subscribe(function($message){
    echo $message;
});
```

> 由於 ReactiveX 需要使用事件循環，請注意一定要在 Swoole Server 啟動之後再調用 ReactiveX 相關 API 。

## 參考資料

* [Rx 中文文檔](https://mcxiaoke.gitbooks.io/rxdocs/content/)
* [Rx 英文文檔](http://reactivex.io/)
* [RxPHP 倉庫](https://github.com/ReactiveX/RxPHP)
