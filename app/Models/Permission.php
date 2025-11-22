<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;
    protected $fillable = ['name', 'description', 'sort_order'];

    public static function defaultPermissions(): array
    {
        return [
            // --- Пространства ---
            ['name' => 'space_read', 'description' => 'Просмотр пространства', 'sort_order' => 1],
            ['name' => 'space_create', 'description' => 'Создание пространства', 'sort_order' => 2],
            ['name' => 'space_edit', 'description' => 'Редактирование пространства', 'sort_order' => 3],
            ['name' => 'space_delete', 'description' => 'Удаление пространства', 'sort_order' => 4],
            ['name' => 'space_manage', 'description' => 'Полный доступ к пространству', 'sort_order' => 5],

            // --- Колонки ---
            ['name' => 'column_read', 'description' => 'Просмотр колонок', 'sort_order' => 10],
            ['name' => 'column_create', 'description' => 'Создание колонок', 'sort_order' => 11],
            ['name' => 'column_edit', 'description' => 'Редактирование колонок', 'sort_order' => 12],
            ['name' => 'column_delete', 'description' => 'Удаление колонок', 'sort_order' => 13],
            ['name' => 'column_manage', 'description' => 'Полный доступ к колонкам', 'sort_order' => 14],

            // --- Задачи ---
            ['name' => 'task_read', 'description' => 'Просмотр задач и чек-листов', 'sort_order' => 20],
            ['name' => 'task_create', 'description' => 'Создание задач', 'sort_order' => 21],
            ['name' => 'task_edit', 'description' => 'Редактирование задач и чек-листов', 'sort_order' => 22],
            ['name' => 'task_delete', 'description' => 'Удаление задач', 'sort_order' => 23],
            ['name' => 'task_manage', 'description' => 'Полный доступ к задачам', 'sort_order' => 24],

            // --- Комментарии ---
            ['name' => 'comment_read', 'description' => 'Просмотр комментариев', 'sort_order' => 30],
            ['name' => 'comment_create', 'description' => 'Добавление комментариев', 'sort_order' => 31],
            ['name' => 'comment_edit', 'description' => 'Редактирование своих комментариев', 'sort_order' => 32],
            ['name' => 'comment_delete', 'description' => 'Удаление своих комментариев', 'sort_order' => 33],
            ['name' => 'comment_manage', 'description' => 'Полный доступ к комментариям', 'sort_order' => 34],
        ];
    }
}
