<?php

namespace Plank\Mediable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Mediable Trait.
 *
 * Provides functionality for attaching media to an eloquent model.
 *
 * @author Sean Fraser <sean@plankdesign.com>
 *
 * Whether the model should automatically reload its media relationship after modification.
 *
 * @property MediableCollection $media
 * @property Pivot $pivot
 * @method static Builder withMediaMatchAll($tags = [])
 *
 */
trait Mediable
{
    /**
     * List of media tags that have been modified since last load.
     * @var string[]
     */
    private $mediaDirtyTags = [];

    /**
     * Boot the Mediable trait.
     *
     * @return void
     */
    public static function bootMediable()
    {
        static::deleted(function (Model $model) {
            $model->handleMediableDeletion();
        });
    }

    /**
     * Relationship for all attached media.
     * @return MorphToMany
     */
    public function media()
    {
        return $this
            ->morphToMany(
                config('mediable.model'),
                'mediable',
                config('mediable.mediables_table', 'mediables')
            )
            ->withPivot('tag', 'order')
            ->orderBy('order');
    }

    /**
     * Query scope to detect the presence of one or more attached media for a given tag.
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  string|string[] $tags
     * @param  bool $matchAll
     * @return void
     */
    public function scopeWhereHasMedia(Builder $q, $tags, bool $matchAll = false)
    {
        if ($matchAll && is_array($tags) && count($tags) > 1) {
            $this->scopeWhereHasMediaMatchAll($q, $tags);
            return;
        }
        $q->whereHas('media', function (Builder $q) use ($tags) {
            $q->whereIn('tag', (array)$tags);
        });
    }

    /**
     * Query scope to detect the presence of one or more attached media that is bound to all of the specified tags simultaneously.
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  string|string[] $tags
     * @return void
     */
    public function scopeWhereHasMediaMatchAll(Builder $q, array $tags)
    {
        $grammar = $q->getQuery()->getGrammar();
        $subquery = $this->newMatchAllQuery($tags)
            ->selectRaw('count(*)')
            ->whereRaw($grammar->wrap($this->mediaQualifiedForeignKey()) . ' = ' . $grammar->wrap($this->getQualifiedKeyName()));
        $q->whereRaw('(' . $subquery->toSql() . ') >= 1', $subquery->getBindings());
    }

    /**
     * Query scope to eager load attached media.
     *
     * @param  Builder $q
     * @param  string|string[] $tags If one or more tags are specified, only media attached to those tags will be loaded.
     * @param  bool $matchAll Only load media matching all provided tags
     * @return void
     */
    public function scopeWithMedia(Builder $q, $tags = [], bool $matchAll = false)
    {
        $tags = (array)$tags;

        if (empty($tags)) {
            $q->with('media');
            return;
        }

        if ($matchAll) {
            $q->withMediaMatchAll($tags);
            return;
        }

        $q->with([
            'media' => function (MorphToMany $q) use ($tags) {
                $this->wherePivotTagIn($q, $tags);
            }
        ]);
    }

    /**
     * Query scope to eager load attached media assigned to multiple tags.
     * @param  Builder $q
     * @param  string|string[] $tags
     * @return void
     */
    public function scopeWithMediaMatchAll(Builder $q, $tags = [])
    {
        $tags = (array)$tags;
        $q->with([
            'media' => function (MorphToMany $q) use ($tags) {
                $this->addMatchAllToEagerLoadQuery($q, $tags);
            }
        ]);
    }

    /**
     * Lazy eager load attached media relationships.
     * @param  string|string[] $tags If one or more tags are specified, only media attached to those tags will be loaded.
     * @param  bool $matchAll Only load media matching all provided tags
     * @return $this
     */
    public function loadMedia($tags = [], bool $matchAll = false)
    {
        $tags = (array)$tags;

        if (empty($tags)) {
            return $this->load('media');
        }

        if ($matchAll) {
            return $this->loadMediaMatchAll($tags);
        }

        $this->load([
            'media' => function (MorphToMany $q) use ($tags) {
                $this->wherePivotTagIn($q, $tags);
            }
        ]);

        return $this;
    }

    /**
     * Lazy eager load attached media relationships matching all provided tags.
     * @param  string|string[] $tags one or more tags
     * @return $this
     */
    public function loadMediaMatchAll($tags = [])
    {
        $tags = (array)$tags;
        $this->load([
            'media' => function (MorphToMany $q) use ($tags) {
                $this->addMatchAllToEagerLoadQuery($q, $tags);
            }
        ]);

        return $this;
    }

    /**
     * Attach a media entity to the model with one or more tags.
     * @param mixed $media Either a string or numeric id, an array of ids, an instance of `Media` or an instance of `\Illuminate\Database\Eloquent\Collection`
     * @param string|string[] $tags One or more tags to define the relation
     * @return void
     */
    public function attachMedia($media, $tags)
    {
        $tags = (array)$tags;
        $increments = $this->getOrderValueForTags($tags);

        $ids = $this->extractIds($media);

        foreach ($tags as $tag) {
            $attach = [];
            foreach ($ids as $id) {
                $attach[$id] = [
                    'tag' => $tag,
                    'order' => ++$increments[$tag],
                ];
            }
            $this->media()->attach($attach);
        }

        $this->markMediaDirty($tags);
    }

    /**
     * Replace the existing media collection for the specified tag(s).
     * @param mixed $media
     * @param string|string[] $tags
     * @return void
     */
    public function syncMedia($media, $tags)
    {
        $this->detachMediaTags($tags);
        $this->attachMedia($media, $tags);
    }

    /**
     * Detach a media item from the model.
     * @param  mixed $media
     * @param  string|string[]|null $tags
     * If provided, will remove the media from the model for the provided tag(s) only
     * If omitted, will remove the media from the media for all tags
     * @return void
     */
    public function detachMedia($media, $tags = null)
    {
        $query = $this->media();
        if ($tags) {
            $query->wherePivotIn('tag', (array)$tags);
        }
        $query->detach($media);
        $this->markMediaDirty($tags);
    }

    /**
     * Remove one or more tags from the model, detaching any media using those tags.
     * @param  string|string[] $tags
     * @return void
     */
    public function detachMediaTags($tags)
    {
        $this->media()->newPivotStatement()
            ->where($this->media()->getMorphType(), $this->media()->getMorphClass())
            ->where($this->mediaQualifiedForeignKey(), $this->getKey())
            ->whereIn('tag', (array)$tags)->delete();
        $this->markMediaDirty($tags);
    }

    /**
     * Check if the model has any media attached to one or more tags.
     * @param  string|string[] $tags
     * @param  bool $match_all
     * If false, will return true if the model has any attach media for any of the provided tags
     * If true, will return true is the model has any media that are attached to all of provided tags simultaneously
     * @return bool
     */
    public function hasMedia($tags, bool $matchAll = false)
    {
        return count($this->getMedia($tags, $matchAll)) > 0;
    }

    /**
     * Retrieve media attached to the model.
     * @param  string|string[] $tags
     * @param  bool $match_all
     * If false, will return media attached to any of the provided tags
     * If true, will return media attached to all of the provided tags simultaneously
     * @return \Illuminate\Database\Eloquent\Collection|\Plank\Mediable\Media[]
     */
    public function getMedia($tags, bool $matchAll = false)
    {
        if ($matchAll) {
            return $this->getMediaMatchAll($tags);
        }

        $this->rehydrateMediaIfNecessary($tags);

        return $this->media
            //exclude media not matching at least one tag
            ->filter(function (Media $media) use ($tags) {
                return in_array($media->pivot->tag, (array)$tags);
            })->keyBy(function (Media $media) {
                return $media->getKey();
            })->values();
    }

    /**
     * Retrieve media attached to multiple tags simultaneously.
     * @param string[] $tags
     * @return \Illuminate\Database\Eloquent\Collection|\Plank\Mediable\Media[]
     */
    public function getMediaMatchAll(array $tags)
    {
        $this->rehydrateMediaIfNecessary($tags);

        //group all tags for each media
        $modelTags = $this->media->reduce(function ($carry, Media $media) {
            $carry[$media->getKey()][] = $media->pivot->tag;

            return $carry;
        }, []);

        //exclude media not matching all tags
        return $this->media->filter(function (Media $media) use ($tags, $modelTags) {
            return count(array_intersect($tags, $modelTags[$media->getKey()])) === count($tags);
        })->keyBy(function (Media $media) {
            return $media->getKey();
        })->values();
    }

    /**
     * Shorthand for retrieving the first attached media item.
     * @param  string|string[] $tags
     * @param  bool $match_all
     * @see \Plank\Mediable\Mediable::getMedia()
     * @return \Plank\Mediable\Media|null
     */
    public function firstMedia($tags, bool $matchAll = false)
    {
        return $this->getMedia($tags, $matchAll)->first();
    }

    /**
     * Shorthand for retrieving the last attached media item.
     * @param  string|string[] $tags
     * @param  bool $match_all
     * @see \Plank\Mediable\Mediable::getMedia()
     * @return \Plank\Mediable\Media|null
     */
    public function lastMedia($tags, $matchAll = false)
    {
        return $this->getMedia($tags, $matchAll)->last();
    }

    /**
     * Retrieve all media grouped by tag name.
     * @return MediableCollection
     */
    public function getAllMediaByTag()
    {
        $this->rehydrateMediaIfNecessary();

        return $this->media->groupBy('pivot.tag');
    }

    /**
     * Get a list of all tags that the media is attached to.
     * @param  \Plank\Mediable\Media $media
     * @return string[]
     */
    public function getTagsForMedia(Media $media)
    {
        $this->rehydrateMediaIfNecessary();

        return $this->media->reduce(function ($carry, Media $item) use ($media) {
            if ($item->getKey() === $media->getKey()) {
                $carry[] = $item->pivot->tag;
            }

            return $carry;
        }, []);
    }

    /**
     * Indicate that the media attached to the provided tags has been modified.
     * @param  string|string[] $tags
     * @return void
     */
    protected function markMediaDirty($tags)
    {
        foreach ((array)$tags as $tag) {
            $this->mediaDirtyTags[$tag] = $tag;
        }
    }

    /**
     * Check if media attached to the specified tags has been modified.
     * @param  null|string|string[] $tags
     * If omitted, will return `true` if any tags have been modified
     * @return bool
     */
    protected function mediaIsDirty($tags = null)
    {
        if (is_null($tags)) {
            return count($this->mediaDirtyTags);
        } else {
            return count(array_intersect((array)$tags, $this->mediaDirtyTags));
        }
    }

    /**
     * Reloads media relationship if allowed and necessary.
     * @param  null|string|string[] $tags
     * @return void
     */
    protected function rehydrateMediaIfNecessary($tags = null)
    {
        if ($this->rehydratesMedia() && $this->mediaIsDirty($tags)) {
            $this->loadMedia();
        }
    }

    /**
     * Check whether the model is allowed to automatically reload media relationship.
     *
     * Can be overridden by setting protected property `$rehydrates_media` on the model.
     * @return bool
     */
    protected function rehydratesMedia()
    {
        if (property_exists($this, 'rehydrates_media')) {
            return $this->rehydrates_media;
        }

        return config('mediable.rehydrate_media', true);
    }

    /**
     * Generate a query builder for.
     * @param  string|string[] $tags
     * @return \Illuminate\Database\Query\Builder
     */
    protected function newMatchAllQuery($tags = [])
    {
        $tags = (array)$tags;
        $grammar = $this->media()->getBaseQuery()->getGrammar();
        return $this->media()->newPivotStatement()
            ->where($this->media()->getMorphType(), $this->media()->getMorphClass())
            ->whereIn('tag', $tags)
            ->groupBy($this->mediaQualifiedRelatedKey())
            ->havingRaw('count(' . $grammar->wrap($this->mediaQualifiedRelatedKey()) . ') = ' . count($tags));
    }

    /**
     * Modify an eager load query to only load media assigned to all provided tags simultaneously.
     * @param  \Illuminate\Database\Eloquent\Relations\MorphToMany $q
     * @param  string|string[] $tags
     * @return void
     */
    protected function addMatchAllToEagerLoadQuery(MorphToMany $q, $tags = [])
    {
        $tags = (array)$tags;
        $grammar = $q->getBaseQuery()->getGrammar();
        $subquery = $this->newMatchAllQuery($tags)->select($this->mediaQualifiedRelatedKey());
        $q->whereRaw($grammar->wrap($this->mediaQualifiedRelatedKey()) . ' IN (' . $subquery->toSql() . ')',
            $subquery->getBindings());
        $this->wherePivotTagIn($q, $tags);
    }

    /**
     * Determine whether media relationships should be detached when the model is deleted or soft deleted.
     * @return void
     */
    protected function handleMediableDeletion()
    {
        // only cascade soft deletes when configured
        if (static::hasGlobalScope(SoftDeletingScope::class) && !$this->forceDeleting) {
            if (config('mediable.detach_on_soft_delete')) {
                $this->media()->detach();
            }
            // always cascade for hard deletes
        } else {
            $this->media()->detach();
        }
    }

    /**
     * Determine the highest order value assigned to each provided tag.
     * @param  string|string[] $tags
     * @return array
     */
    private function getOrderValueForTags($tags)
    {
        $q = $this->media()->newPivotStatement();
        $tags = array_map('strval', (array)$tags);
        $grammar = $q->getGrammar();

        $result = $q->selectRaw($grammar->wrap('tag') . ', max(' . $grammar->wrap('order') . ') as aggregate')
            ->where('mediable_type', $this->getMorphClass())
            ->where('mediable_id', $this->getKey())
            ->whereIn('tag', $tags)
            ->groupBy('tag')
            ->pluck('aggregate', 'tag');

        $empty = array_combine($tags, array_fill(0, count($tags), 0));

        $merged = collect($result)->toArray() + $empty;
        return $merged;
    }

    /**
     * Convert mixed input to array of ids.
     * @param  mixed $input
     * @return array
     */
    private function extractIds($input)
    {
        if ($input instanceof Collection) {
            return $input->modelKeys();
        }

        if ($input instanceof Media) {
            return [$input->getKey()];
        }

        return (array)$input;
    }

    /**
     * {@inheritdoc}
     */
    public function load($relations)
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        if (array_key_exists('media', $relations)
            || in_array('media', $relations)
        ) {
            $this->mediaDirtyTags = [];
        }

        return parent::load($relations);
    }

    /**
     * {@inheritdoc}
     * @return MediableCollection
     */
    public function newCollection(array $models = [])
    {
        return new MediableCollection($models);
    }

    /**
     * Key the name of the foreign key field of the media relation
     *
     * Accounts for the change of method name in Laravel 5.4
     *
     * @return string
     */
    private function mediaQualifiedForeignKey()
    {
        $relation = $this->media();
        if (method_exists($relation, 'getQualifiedForeignPivotKeyName')) {
            // Laravel 5.5
            return $relation->getQualifiedForeignPivotKeyName();
        } elseif (method_exists($relation, 'getQualifiedForeignKeyName')) {
            // Laravel 5.4
            return $relation->getQualifiedForeignKeyName();
        }
        // Laravel <= 5.3
        return $relation->getForeignKey();
    }

    /**
     * Key the name of the related key field of the media relation
     *
     * Accounts for the change of method name in Laravel 5.4 and again in Laravel 5.5
     *
     * @return string
     */
    private function mediaQualifiedRelatedKey()
    {
        $relation = $this->media();
        if (method_exists($relation, 'getQualifiedRelatedPivotKeyName')) {
            // Laravel 5.5
            return $relation->getQualifiedRelatedPivotKeyName();
        } elseif (method_exists($relation, 'getQualifiedRelatedKeyName')) {
            // Laravel 5.4
            return $relation->getQualifiedRelatedKeyName();
        }
        // Laravel <= 5.3
        return $relation->getOtherKey();
    }

    /**
     * perform a WHERE IN on the pivot table's tags column
     *
     * Adds support for Laravel <= 5.2, which does not provide a `wherePivotIn()` method
     * @param  \Illuminate\Database\Eloquent\Relations\MorphToMany $q
     * @param  string|string[] $tags
     * @return void
     */
    private function wherePivotTagIn(MorphToMany $q, $tags = [])
    {
        method_exists($q, 'wherePivotIn') ? $q->wherePivotIn('tag',
            $tags) : $q->whereIn($this->media()->getTable() . '.tag', $tags);
    }
}
