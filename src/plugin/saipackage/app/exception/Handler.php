<?php
namespace plugin\saipackage\app\exception;

use plugin\saipackage\app\controller\ManagementController;
use Saithink\Saipackage\management\ManagementException;
use Webman\Http\Request;
use Webman\Http\Response;

/** Webman renders controller exceptions inside its innermost callback, before outer middleware. */
final class Handler extends \plugin\saiadmin\app\exception\Handler
{
    public function report(\Throwable $exception)
    {
        if ((request()->controller ?? '') === ManagementController::class) return;
        parent::report($exception);
    }

    public function render(Request $request, \Throwable $exception): Response
    {
        if (($request->controller ?? '') !== ManagementController::class) return parent::render($request, $exception);
        $status = in_array($exception->getCode(), [401, 403, 404, 409, 422, 503], true) ? $exception->getCode() : 400;
        $message = $exception instanceof ManagementException ? $exception->getMessage() : 'Plugin management operation failed';
        return json(['code' => $status, 'message' => $message, 'data' => null])->withStatus($status);
    }
}
