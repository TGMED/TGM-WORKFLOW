<?php

namespace App\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The one page every error lands on, in the app's own clothes rather than the
 * framework's.
 *
 * A message the app wrote itself (an abort with something to say) is shown as
 * written. Anything the framework made up on the way, a model's class name in
 * a 404 or a route's methods in a 405, is replaced with plain words: it means
 * nothing to the person reading, and says more about the code than it should.
 */
final class ErrorPage
{
    /**
     * Statuses whose own message is the app's, and worth showing.
     */
    private const OWN_WORDS = [403, 410, 422];

    /**
     * Messages the framework supplies by default, which are never the app's.
     */
    private const STOCK = [
        'This action is unauthorized.',
        'Forbidden',
        'Unprocessable Content',
        'Gone',
    ];

    public static function render(Request $request, Throwable $e, int $status): Response
    {
        [$title, $fallback] = self::copy($status);

        return Inertia::render('Error', [
            'status' => $status,
            'title' => $title,
            'message' => self::ownMessage($e, $status) ?? $fallback,
        ])
            ->toResponse($request)
            ->setStatusCode($status);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function copy(int $status): array
    {
        return match (true) {
            $status === 403 => ['You cannot open this', 'Your account does not give you access to this page. If you think it should, ask the people team.'],
            $status === 404, $status === 405 => ['Page not found', 'There is nothing here. The link may be out of date, or the page may have moved.'],
            $status === 410 => ['This is no longer here', 'Whatever this link pointed to has been used up or taken down.'],
            $status === 422 => ['That could not be done', 'Something about the request did not add up. Go back and try again.'],
            $status === 429 => ['Slow down a moment', 'That has been tried too many times in a row. Wait a minute, then try again.'],
            $status === 503 => ['Back shortly', 'The app is being updated. Give it a few minutes and try again.'],
            $status >= 500 => ['Something went wrong on our side', 'It is not anything you did. Try again in a moment, and if it keeps happening, tell the people team.'],
            default => ['That did not work', 'Go back and try again.'],
        };
    }

    private static function ownMessage(Throwable $e, int $status): ?string
    {
        if (! $e instanceof HttpExceptionInterface || ! in_array($status, self::OWN_WORDS, true)) {
            return null;
        }

        $message = trim($e->getMessage());

        return $message === '' || in_array($message, self::STOCK, true) ? null : $message;
    }
}
