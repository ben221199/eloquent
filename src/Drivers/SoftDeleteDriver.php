<?php
/*
 * Copyright 2022 Cloud Creativity Limited
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace LaravelJsonApi\Eloquent\Drivers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class SoftDeleteDriver extends StandardDriver
{

    /**
     * SoftDeleteDriver constructor.
     *
     * @param Model|SoftDeletes $model
     */
    public function __construct($model)
    {
        if (!in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            throw new InvalidArgumentException('Expecting a model that is soft-deletable.');
        }

        parent::__construct($model);
    }

    /**
     * @param Model $model
     * @return bool
     */
    private function usesDeletedAt($model): bool{
        return method_exists($model, 'getDeletedAtColumn');
    }

    /**
     * @param Model $model
     * @return bool
     */
    private function usesIsDeleted($model): bool{
        return method_exists($model, 'getIsDeletedColumn');
    }

    /**
     * @inheritDoc
     */
    public function query(): Builder
    {
        /**
         * When querying specific resources, we use `withTrashed` as we want trashed
         * resources to exist in our API.
         */
        return parent::query()->withTrashed();
    }

    /**
     * @inheritDoc
     */
    public function persist(Model $model): bool
    {
        /**
         * If the model is being restored, the Laravel restore method executes a
         * save on the model. So we only need to run the restore method and all
         * dirty attributes will be saved.
         */
        if ($this->willRestore($model)) {
            return $this->restore($model);
        }

        /**
         * To ensure Laravel still executes its soft-delete logic (e.g. firing events)
         * we need to delete before a save when we are soft-deleting. Although this
         * may result in two database calls in this scenario, it means we can guarantee
         * that standard Laravel soft-delete logic is executed.
         *
         * When executing the soft delete, Laravel will apply a fresh timestamp to the
         * model's deleted at column. As the JSON:API client may have provided a different
         * timestamp, we back up that value first, execute the soft delete, then reapply
         * the timestamp.
         *
         * @see https://github.com/cloudcreativity/laravel-json-api/issues/371
         */
        if ($this->willSoftDelete($model)) {
            if($this->usesIsDeleted($model)){
                $column = $model->getIsDeletedColumn();
                // save the original boolean so we can put it back later on.
                $isDeleted = $model->{$column};
                // delete the record so that deleting and deleted events get fired.
                $model->delete();
                // apply the original boolean back before saving, so that we keep boolean provided by the client.
                $model->{$column} = $isDeleted;
            }
            if($this->usesDeletedAt($model)){
                $column = $model->getDeletedAtColumn();
                // save the original date so we can put it back later on.
                $deletedAt = $model->{$column};
                // delete the record so that deleting and deleted events get fired.
                $model->delete();
                // apply the original date back before saving, so that we keep date provided by the client.
                $model->{$column} = $deletedAt;
            }
        }

        return (bool) $model->save();
    }

    /**
     * @inheritDoc
     */
    public function destroy(Model $model): bool
    {
        return (bool) $model->forceDelete();
    }

    /**
     * @param Model|SoftDeletes $model
     * @return bool
     */
    private function restore(Model $model): bool
    {
        return (bool) $model->restore();
    }

    /**
     * Will the hydration operation restore the model?
     *
     * @param Model|SoftDeletes $model
     * @return bool
     */
    private function willRestore(Model $model): bool
    {
        if (!$model->exists) {
            return false;
        }

        if($this->usesDeletedAt($model)){
            $column = $model->getDeletedAtColumn();
            return $model->getOriginal($column) && !$model->{$column};
        }

        if($this->usesIsDeleted($model)){
            $column = $model->getIsDeletedColumn();
            return $model->getOriginal($column) && !$model->{$column};
        }

        return false;
    }

    /**
     * Will the hydration operation result in the model being soft deleted?
     *
     * @param Model|SoftDeletes $model
     * @return bool
     */
    private function willSoftDelete(Model $model): bool
    {
        if (!$model->exists) {
            return false;
        }

        if($this->usesDeletedAt($model)){
            $column = $model->getDeletedAtColumn();
            return null === $model->getOriginal($column) && null !== $model->{$column};
        }

        if($this->usesIsDeleted($model)){
            $column = $model->getIsDeletedColumn();
            return !$model->getOriginal($column) && $model->{$column};
        }

        return false;
    }

}
