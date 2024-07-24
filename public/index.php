<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Middleware\MethodOverrideMiddleware;
use DI\Container;
use Valitron\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use App\Connection;
use App\SqlQuery;
use Carbon\Carbon;
use DiDom\Document;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$container->set('connection', function () {
    $pdo = Connection::get()->connect();
    return $pdo;
});

$container->set('flash', function () {
    return new Messages();
});

$app = AppFactory::createFromContainer($container);
$app->add(MethodOverrideMiddleware::class);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'main.phtml');
})->setName("index");

$app->get('/urls', function ($request, $response) {
    $dataBase = new SqlQuery($this->get('connection'));

    $dataFromBase = $dataBase->query('SELECT id, name FROM urls ORDER BY id DESC');
    $dataFromChecks = $dataBase->query(
        'SELECT url_id, MAX(created_at) AS created_at, status_code
        FROM url_checks
        GROUP BY url_id, status_code'
    );

    $combinedData = array_map(function ($url) use ($dataFromChecks) {
        foreach ($dataFromChecks as $check) {
            if ($url['id'] === $check['url_id']) {
                $url['created_at'] = $check['created_at'];
                $url['status_code'] = $check['status_code'];
            }
        }
        return $url;
    }, $dataFromBase);

    $params = ['data' => $combinedData];
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName("urls.store");

$app->post('/urls', function ($request, $response) use ($router) {
    $urls = $request->getParsedBodyParam('url');
    $dataBase = new SqlQuery($this->get('connection'));
    $error = [];

    $v = new Validator(array('name' => $urls['name'], 'count' => strlen((string) $urls['name'])));
    $v->rule('required', 'name')->rule('lengthMax', 'count.*', 255)->rule('url', 'name');

    if ($v->validate()) {
        $parseUrl = parse_url($urls['name']);
        $urls['name'] = "{$parseUrl['scheme']}://{$parseUrl['host']}";

        $searchName = $dataBase->query('SELECT id FROM urls WHERE name = :name', $urls);

        if (count($searchName) !== 0) {
            $this->get('flash')->addMessage('success', 'Страница уже существует');
            return $response->withRedirect(
                $router->urlFor('urls.show', ['id' => $searchName[0]['id']])
            );
        }

        $urls['time'] = Carbon::now();
        $dataBase->query('INSERT INTO urls(name, created_at) VALUES(:name, :time) RETURNING id', $urls);

        $id = $dataBase->query('SELECT MAX(id) FROM urls');
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

        return $response->withRedirect($router->urlFor('urls.show', ['id' => $id[0]['max']]));
    } else {
        if (isset($urls) && strlen($urls['name']) < 1) {
            $error['name'] = 'URL не должен быть пустым';
        } elseif (isset($urls)) {
            $error['name'] = 'Некорректный URL';
        }
    }

    $params = ['erorrs' => $error];
    return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', $params);
});

$app->get('/urls/{id:[0-9]+}', function ($request, $response, $args) {
    $dataBase = new SqlQuery($this->get('connection'));

    $dataFromBase = $dataBase->query('SELECT * FROM urls WHERE id = :id', $args);
    if (empty($dataFromBase)) {
        return $this->get('renderer')->render($response->withStatus(404), 'error404.phtml');
    }

    $messages = $this->get('flash')->getMessages();
    $dataFromChecks = $dataBase->query('SELECT * FROM url_checks WHERE url_id = :id ORDER BY id DESC', $args);

    $params = ['data' => $dataFromBase, 'flash' => $messages, 'checks' => $dataFromChecks];
    return $this->get('renderer')->render($response, 'url.phtml', $params);
})->setName("urls.show");

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {
    $id = $args['id'];

    $v = new Validator(['id' => $id]);
    $v->rules(['required' => 'id', 'integer' => 'id']);
    if (! $v->validate()) {
        $errors = $v->errors();
        foreach ($errors as $arr) {
            foreach ($arr as $error) {
                $this->get('flash')->addMessage('failure', $error);
            }
        }
        return $response->withRedirect(
            $router->urlFor('urls.show', ['id' => $id])
        );
    }

    $urls['url_id'] = $id;

    $dataBase = new SqlQuery($this->get('connection'));
    $name = $dataBase->query('SELECT name FROM urls WHERE id = :url_id', $urls);

    $urls['time'] = Carbon::now();
    $client = new Client();
    try {
        $res = $client->request('GET', $name[0]['name'], ['http_errors' => true]);
        $urls['status'] = $res->getStatusCode();
    } catch (ClientException $e) {
        $urls['status'] = $e->getResponse()->getStatusCode();
        $urls['title'] = 'Доступ ограничен: проблема с IP';
        $urls['h1'] = 'Доступ ограничен: проблема с IP';

        $dataBase->query('INSERT INTO url_checks(url_id, status_code, title, h1, created_at)
            VALUES(:url_id, :status, :title, :h1, :time)', $urls);

        $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил с ошибкой');
        return $response->withRedirect(
            $router->urlFor('urls.show', ['id' => $id])
        );
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('failure', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response->withRedirect(
            $router->urlFor('urls.show', ['id' => $id])
        );
    } catch (TransferException $e) {
        $this->get('flash')->addMessage('error', 'Упс, что-то пошло не так...');
        return $response->withRedirect(
            $router->urlFor('urls.show', ['id' => $id])
        );
    }

    $htmlFromUrl = (string) $res->getBody();
    $document = new Document($htmlFromUrl);

    $title = optional($document->first('title'));
    if ($title !== null) {
        $urls['title'] = $title->text();
    }

    $h1 = optional($document->first('h1'));
    if ($h1 !== null) {
        $urls['h1'] = $h1->text();
    }

    $meta = optional($document->first('meta[name="description"]'));
    if ($meta !== null) {
        $urls['meta'] = $meta->getAttribute('content');
    }

    $dataBase->query(
        'INSERT INTO url_checks(url_id, status_code, h1, title, description, created_at) 
        VALUES(:url_id, :status, :h1, :title, :meta, :time)',
        $urls
    );

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');

    return $response->withRedirect(
        $router->urlFor('urls.show', ['id' => $id])
    );
})->setName("urls.checks");

$app->run();
