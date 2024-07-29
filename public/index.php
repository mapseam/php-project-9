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

    $dataFromBase = $dataBase->select('SELECT id, name FROM urls ORDER BY id DESC');
    $dataFromChecks = $dataBase->select(
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
    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName("urls.store");

$app->post('/urls', function ($request, $response) use ($router) {
    $data = $request->getParsedBodyParam('data');

    $validator = new Valitron\Validator(['url' => $data['url']]);
    $validator->stopOnFirstFail();
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('Длина URL более 255 символов');

    $errors = [];
    if (! $validator->validate() && isset($validator->errors()['url'])) {
        $errors = $validator->errors()['url'];
        $params = ['errors' => $errors];
        return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', $params);
    }
    
    $parsedUrl = parse_url(mb_strtolower($data['url']));
    $data['url'] = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";

    $dataBase = new SqlQuery($this->get('connection'));
    $searchName = $dataBase->select('SELECT id FROM urls WHERE name = :url', $data);

    if (count($searchName) !== 0) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withRedirect(
            $router->urlFor('urls.show', ['id' => $searchName[0]['id']])
        );
    }

    $data['time'] = Carbon::now();
    $id = $dataBase->insert('INSERT INTO urls(name, created_at) VALUES(:url, :time) RETURNING id', $data);
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

    return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
});

$app->get('/urls/{id:[0-9]+}', function ($request, $response, $args) {
    $dataBase = new SqlQuery($this->get('connection'));

    $dataFromBase = $dataBase->select('SELECT * FROM urls WHERE id = :id', $args);
    if ($dataFromBase == []) {
        return $this->get('renderer')->render($response->withStatus(404), 'error404.phtml');
    }

    $messages = $this->get('flash')->getMessages();
    $dataFromChecks = $dataBase->select('SELECT * FROM url_checks WHERE url_id = :id ORDER BY id DESC', $args);

    $params = ['data' => $dataFromBase, 'flash' => $messages, 'checks' => $dataFromChecks];
    return $this->get('renderer')->render($response, 'url.phtml', $params);
})->setName("urls.show");

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {
    $id = $args['id'];

    $validator = new Validator(['id' => $id]);
    $validator->stopOnFirstFail();
    $validator->rule('required', 'id')->message('id не должен быть пустым');
    $validator->rule('integer', 'id')->message('id должен быть целым числом');

    if (! $validator->validate() && isset($validator->errors()['id'])) {
        $errors = $validator->errors()['id'];

        foreach ($errors as $error) {
            $this->get('flash')->addMessage('failure', $error);
        }
        return $response->withRedirect(
            $router->urlFor('urls.show', ['id' => $id])
        );
    }

    $data['url_id'] = $id;

    $dataBase = new SqlQuery($this->get('connection'));
    $url = $dataBase->select('SELECT name FROM urls WHERE id = :url_id', $data);

    $data['time'] = Carbon::now();
    $client = new Client();
    try {
        $res = $client->request('GET', $url[0]['name'], ['http_errors' => true]);
        $data['status'] = $res->getStatusCode();
    } catch (ClientException $e) {
        $data['status'] = $e->getResponse()->getStatusCode();
        $data['title'] = 'Доступ ограничен: проблема с IP';
        $data['h1'] = 'Доступ ограничен: проблема с IP';

        $dataBase->insert('INSERT INTO url_checks(url_id, status_code, title, h1, created_at)
            VALUES(:url_id, :status, :title, :h1, :time)', $data);

        $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил с ошибкой');
        return $response->withRedirect(
            $router->urlFor('urls.show', ['id' => $id])
        );
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('failure', 'Произошла ошибка при проверке - не удалось подключиться к серверу');
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
        $data['title'] = $title->text();
    }

    $h1 = optional($document->first('h1'));
    if ($h1 !== null) {
        $data['h1'] = $h1->text();
    }

    $description = optional($document->first('meta[name="description"]'));
    if ($description !== null) {
        $data['meta'] = $description->getAttribute('content');
    }

    $dataBase->insert(
        'INSERT INTO url_checks(url_id, status_code, h1, title, description, created_at) 
        VALUES(:url_id, :status, :h1, :title, :meta, :time)',
        $data
    );

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');

    return $response->withRedirect(
        $router->urlFor('urls.show', ['id' => $id])
    );
})->setName("urls.checks");

$app->run();
