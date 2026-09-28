<?php

namespace Ahinkle\FileBoomerang;

use Ahinkle\FileBoomerang\Exceptions\InvalidBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

readonly class Batch
{
    public const int Version = 1;

    /**
     * @param  Collection<int, Change>  $changes
     */
    public function __construct(
        public string $id,
        protected CarbonImmutable $createdAt,
        public string $host,
        public ?Editor $editor,
        public Collection $changes,
    ) {}

    /**
     * @param  Collection<int, Change>  $changes
     */
    public static function record(Collection $changes, ?Editor $editor): static
    {
        $now = now()->toImmutable();

        return tap(
            new static((string) Str::ulid($now), $now, gethostname() ?: 'unknown', $editor, $changes->values()),
            fn (Batch $batch) => $batch->save(),
        );
    }

    public static function pending(): Batches
    {
        return static::after(null);
    }

    public static function after(?string $id): Batches
    {
        return Batches::make(
            collect(Mailbox::disk()->files(Mailbox::path('batches')))
                ->filter(fn (string $key) => str_ends_with($key, '.json'))
                ->map(fn (string $key) => basename($key, '.json'))
                ->filter(fn (string $batch) => $id === null || strcmp($batch, $id) > 0)
                ->sort(SORT_STRING)
                ->values()
                ->map(fn (string $batch) => static::find($batch))
        );
    }

    public static function find(string $id): static
    {
        $batch = static::fromArray(
            Mailbox::disk()->json(static::key($id)) ?? throw InvalidBatch::because("[{$id}] is not valid JSON")
        );

        throw_unless($batch->id === $id, InvalidBatch::because("[{$id}] holds the batch [{$batch->id}]"));

        return $batch;
    }

    public function delete(): void
    {
        Mailbox::disk()->delete(static::key($this->id));
    }

    public function createdAt(): CarbonImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array{version: int, id: string, created_at: string, host: string, editor: ?array{name: string, email: string}, ops: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'version' => static::Version,
            'id' => $this->id,
            'created_at' => $this->createdAt->toIso8601String(),
            'host' => $this->host,
            'editor' => $this->editor?->toArray(),
            'ops' => $this->changes->map->toArray()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): static
    {
        throw_unless(($attributes['version'] ?? null) === static::Version, InvalidBatch::because('its version is not supported'));
        throw_unless(Str::isUlid($attributes['id'] ?? null), InvalidBatch::because('its id is not a ULID'));
        throw_unless(is_string($attributes['host'] ?? null), InvalidBatch::because('it has no host'));
        throw_unless(array_key_exists('editor', $attributes), InvalidBatch::because('it has no editor'));
        throw_unless(is_array($attributes['ops'] ?? null) && array_is_list($attributes['ops']), InvalidBatch::because('it has no list of operations'));
        throw_unless(collect($attributes['ops'])->every(fn (mixed $op) => is_array($op)), InvalidBatch::because('an operation is not an object'));

        return new static(
            $attributes['id'],
            static::timestamp($attributes['created_at'] ?? null),
            $attributes['host'],
            static::editor($attributes['editor']),
            collect($attributes['ops'])->map(fn (array $op) => Change::fromArray($op)),
        );
    }

    protected function save(): void
    {
        Mailbox::disk()->put(static::key($this->id), json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    protected static function key(string $id): string
    {
        return Mailbox::path('batches', "{$id}.json");
    }

    protected static function timestamp(mixed $value): CarbonImmutable
    {
        throw_unless(is_string($value), InvalidBatch::because('it has no created_at'));

        return rescue(
            fn () => CarbonImmutable::parse($value),
            fn () => throw InvalidBatch::because('its created_at is not a date'),
            report: false,
        );
    }

    protected static function editor(mixed $editor): ?Editor
    {
        return match (true) {
            is_array($editor) => Editor::fromArray($editor),
            is_null($editor) => null,
            default => throw InvalidBatch::because('its editor is not an object'),
        };
    }
}
