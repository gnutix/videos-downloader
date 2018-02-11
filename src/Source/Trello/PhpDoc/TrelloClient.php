<?php declare(strict_types=1);

namespace App\Source\Trello\PhpDoc;

use Stevenmaguire\Services\Trello\Client;

abstract class TrelloClient extends Client
{
    /**
     * @param string $boardId
     *
     * @return TrelloList[]
     * @throws \Stevenmaguire\Services\Trello\Exceptions\Exception
     */
    abstract public function getBoardLists(string $boardId): array;

    /**
     * @param string $boardId
     *
     * @return TrelloCard[]
     * @throws \Stevenmaguire\Services\Trello\Exceptions\Exception
     */
    abstract public function getBoardCards(string $boardId): array;
}
