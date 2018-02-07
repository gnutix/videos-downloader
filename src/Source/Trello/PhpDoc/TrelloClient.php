<?php declare(strict_types=1);

namespace App\Source\Trello\PhpDoc;

interface TrelloClient
{
    /**
     * @param string $boardId
     *
     * @return TrelloList[]
     * @throws \Stevenmaguire\Services\Trello\Exceptions\Exception
     */
    public function getBoardLists(string $boardId): array;

    /**
     * @param string $boardId
     *
     * @return TrelloCard[]
     * @throws \Stevenmaguire\Services\Trello\Exceptions\Exception
     */
    public function getBoardCards(string $boardId): array;
}
