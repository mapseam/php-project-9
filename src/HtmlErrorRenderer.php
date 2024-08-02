<?php

namespace App;

use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorRendererInterface;
//use Throwable;

class HtmlErrorRenderer implements ErrorRendererInterface
{
    public function __construct(
        protected string $defaultTitle = 'Ошибка приложения Slim',
        protected string $defaultDescription = 'Произошла ошибка. Приносим извинения за временные неудобства.',
    ) {
    }

    protected function getErrorTitle(\Throwable $exception): string
    {
        return $exception instanceof HttpException ? $exception->getTitle() : $this->defaultTitle;
    }

    protected function getErrorDescription(\Throwable $exception): string
    {
        if (!$exception instanceof HttpException) {
            return $this->defaultDescription;
        }

        return $exception->getMessage() !== '' ? $exception->getMessage() : $exception->getDescription();
    }

    public function __invoke(\Throwable $exception, bool $displayErrorDetails): string
    {
        $title = $this->getErrorTitle($exception);
        if ($displayErrorDetails) {
            $content = <<<CONTENT
            <p>Приложение не может выполниться из-за следующей ошибки:</p>
            <h2>Детали</h2>
            {$this->formatException($exception)}
            CONTENT;
        } else {
            $content = '<p>' . $this->getErrorDescription($exception) . '</p>';
        }

        return <<<OUTPUT
        <!doctype html>
        <html lang="ru">
            <head>
                <meta charset="utf-8">
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8">

                <title>{$title}</title>

                <style>
                    body{margin:0;padding:30px;font:12px/1.5 Helvetica,Arial,Verdana,sans-serif}
                    h1{margin:0;font-size:48px;font-weight:normal;line-height:48px}
                    strong{display:inline-block;width:65px}
                </style>
            </head>
            <body>
                <h1>{$title}</h1>
                <div>{$content}</div>
                <p><a href="#" onClick="window.history.go(-1)">Вернуться</a></p>
            </body>
        </html>
        OUTPUT;
    }

    private function formatException(\Throwable $exception): string
    {
        $outputString = <<<'OUTPUT'
        <div><strong>Тип:</strong> %s</div>
        <div><strong>Код:</strong> %s</div>
        <div><strong>Ошибка:</strong> %s</div>
        <div><strong>Файл:</strong> %s</div>
        <div><strong>Строка:</strong> %s</div>
        <!--<h2>Trace</h2>
        <pre>%s</pre>-->
        OUTPUT;

        return sprintf(
            $outputString,
            $exception::class,
            $exception->getCode(),
            htmlentities($exception->getMessage()),
            $exception->getFile(),
            $exception->getLine(),
            "", //htmlentities($exception->getTraceAsString()),
        );
    }
}
