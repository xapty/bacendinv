<?php

namespace App\Repositories;

use App\Models\Comment;
use Core\Model\Model;
use Ramsey\Uuid\Uuid;

class CommentRepositories implements CommentContract
{
    public function create(array $data): Model
    {
        return Comment::create([
            'uuid' => Uuid::uuid4()->toString(),
            ...$data,
            'own' => Uuid::uuid4()->toString()
        ]);
    }

    public function getAll(int $user_id, int $limit, int $offset): Model
    {
        $comments = Comment::with('comments')
            ->select(['uuid', 'name', 'presence', 'comment', 'is_admin', 'gif_url', 'created_at', ...(auth()->user()->isAdmin() ? ['ip', 'own', 'user_agent'] : [])])
            ->where('user_id', $user_id)
            ->whereNull('parent_id')
            ->orderBy('id', 'DESC')
            ->limit(abs($limit))
            ->offset($offset)
            ->get();

        function mappingName(object &$c): void
        {
            if ($c->is_admin) {
                $c->name = auth()->user()->name;
            }

            foreach ($c->comments as &$child) {
                $child->is_parent = false;
                mappingName($child);
            }
        }

        foreach ($comments as &$c) {
            $c->is_parent = true;
            mappingName($c);
        }

        return $comments;
    }

    public function count(int $user_id): int
    {
        return intval(Comment::select('count(id) as comment_count')
            ->where('user_id', $user_id)
            ->whereNull('parent_id')
            ->first()
            ->comment_count);
    }

    public function getByUuid(int $user_id, string $uuid): Model
    {
        return Comment::where('uuid', $uuid)
            ->where('user_id', $user_id)
            ->limit(1)
            ->first();
    }

    public function getByOwnId(int $user_id, string $own_id): Model
    {
        return Comment::where('own', $own_id)
            ->where('user_id', $user_id)
            ->limit(1)
            ->first();
    }

    public function deleteByParentID(string $uuid): int
    {
        return Comment::where('parent_id', $uuid)->delete();
    }

    public function countCommentByUserID(int $id): int
    {
        return Comment::where('user_id', $id)->count('id', 'comments')->first()->comments;
    }

    public function countPresenceByUserID(int $id): Model
    {
        return Comment::where('user_id', $id)
            ->whereNull('parent_id')
            ->where(function ($query) {
                $query->where('is_admin', false)
                    ->whereNull('is_admin', 'OR');
            })
            ->groupBy('user_id')
            ->select([
                'SUM(CASE WHEN presence = TRUE THEN 1 ELSE 0 END) AS present_count',
                'SUM(CASE WHEN presence = FALSE THEN 1 ELSE 0 END) AS absent_count'
            ])
            ->first();
    }

    public function downloadCommentByUserID(int $id): Model
    {
        return Comment::leftJoin('likes', 'comments.uuid', 'likes.comment_id')
            ->where('comments.user_id', $id)
            ->groupBy([
                'comments.uuid',
                'comments.name',
                'comments.presence',
                'comments.is_admin',
                'comments.comment',
                'comments.gif_url',
                'comments.ip',
                'comments.user_agent',
                'comments.created_at',
                'comments.parent_id'
            ])
            ->select([
                'comments.uuid',
                'count(likes.id) as count_like',
                'comments.name',
                'comments.presence',
                'comments.is_admin',
                'comments.comment',
                'comments.gif_url',
                'comments.ip',
                'comments.user_agent',
                'comments.created_at as is_created',
                'comments.parent_id'
            ])
            ->orderBy('is_created', 'DESC')
            ->get();
    }

    public function getByUuidWithoutUser(string $uuid): Model
    {
        return Comment::where('uuid', $uuid)->first();
    }
}
