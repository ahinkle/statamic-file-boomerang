<?php

namespace Ahinkle\FileBoomerang;

use Ahinkle\FileBoomerang\Exceptions\InvalidBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;

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
    public static function record(Collection $changes, ?Editor $editor): self
    {
        $now = now()->toImmutable();

        return tap(new self((string) Str::ulid($now), $now, gethostname() ?: 'unknown', $editor, $changes->values()))->save();
    }

    public static function pending(): Batches
    {
        return static::after(null);
    }

    public static function after(?string $id): Batches
    {
        return Batches::make(static::ids($id)->map(fn (string $batch) => static::find($batch))->filter()->values());
    }

    /**
     * @return Collection<int, string>
     */
    public static function ids(?string $after = null): Collection
    {
        return collect(Mailbox::disk()->files(Mailbox::path('batches')))
            ->filter(fn (string $key) => str_ends_with($key, '.json') && Str::isUlid(basename($key, '.json')))
            ->map(fn (string $key) => basename($key, '.json'))
            ->filter(fn (string $batch) => $after === null || strcmp($batch, $after) > 0)
            ->sort(SORT_STRING)
            ->values();
    }

    public static function find(string $id): ?self
    {
        if (($contents = static::read(static::key($id))) === null) {
            return null;
        }

        try {
            return static::parse($contents, $id);
        } catch (InvalidBatch $invalidBatch) {
            throw $invalidBatch->in(static::key($id));
        }
    }

    public function save(): void
    {
        Mailbox::disk()->put(static::key($this->id), json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
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
     * @return array{version: int, id: string, created_at: string, host: string, editor: ?array{name: string, email: string}, ops: array<array<string, mixed>>}
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
     * @param  array<mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        throw_unless(($attributes['version'] ?? null) === static::Version, InvalidBatch::because('its version is not supported'));
        throw_unless(Str::isUlid($attributes['id'] ?? null), InvalidBatch::because('its id is not a ULID'));
        throw_unless(is_string($attributes['host'] ?? null), InvalidBatch::because('it has no host'));
        throw_unless(array_key_exists('editor', $attributes), InvalidBatch::because('it has no editor'));
        throw_unless(is_array($attributes['ops'] ?? null) && array_is_list($attributes['ops']), InvalidBatch::because('it has no list of operations'));

        return new self(
            $attributes['id'],
            static::timestamp($attributes['created_at'] ?? null),
            $attributes['host'],
            static::editor($attributes['editor']),
            collect($attributes['ops'])->map(fn (mixed $op) => is_array($op) ? Change::fromArray($op) : throw InvalidBatch::because('an operation is not an object')),
        );
    }

    protected static function read(string $key): ?string
    {
        try {
            return Mailbox::disk()->get($key);
        } catch (UnableToReadFile $unableToReadFile) {
            throw_if(Mailbox::disk()->fileExists($key), $unableToReadFile);

            return null;
        }
    }

    protected static function parse(string $contents, string $id): self
    {
        $attributes = json_decode($contents, true);

        throw_unless(is_array($attributes), InvalidBatch::because('it is not a JSON object'));

        $batch = static::fromArray($attributes);

        throw_unless($batch->id === $id, InvalidBatch::because("it holds the batch [{$batch->id}]"));

        return $batch;
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
