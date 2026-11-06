<?php

namespace App\Support;

use App\Queries\EmployeeSearchQuery;
use Illuminate\Http\Request;

/**
 * What a bulk export contains: the directory filters and sort order plus the
 * selection, either "everything matching except these ids" or "only these ids".
 * Kept as plain data so that a queued export can rebuild the same query later
 * ({@see toArray()} / {@see fromArray()}); the user's visibility is applied when
 * the query is built.
 */
final class ExportSelection
{
    /** Request fields with the filters / sort order (EmployeeFilters::fromArray() names). */
    public const FILTER_FIELDS = [
        'search', 'employmentStatus', 'gender', 'department', 'jobTitle',
        'salaryMin', 'salaryMax', 'sortBy', 'sortDirection',
    ];

    /**
     * @param  array<string, scalar>  $filters
     * @param  int[]  $ids  excluded ids (selectAll) or selected ids
     */
    private function __construct(
        public readonly array $filters,
        public readonly bool $selectAll,
        public readonly array $ids,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $selectAll = $request->boolean('selectAll');

        return new self(
            self::scalars($request->only(self::FILTER_FIELDS)),
            $selectAll,
            self::idList($request->input($selectAll ? 'excludedEmployees' : 'selectedEmployees', [])),
        );
    }

    /**
     * @param  array<string, mixed>  $data  a toArray() result
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::scalars(array_intersect_key((array) ($data['filters'] ?? []), array_flip(self::FILTER_FIELDS))),
            (bool) ($data['selectAll'] ?? false),
            self::idList($data['ids'] ?? []),
        );
    }

    /**
     * @return array{filters: array<string, scalar>, selectAll: bool, ids: int[]}
     */
    public function toArray(): array
    {
        return ['filters' => $this->filters, 'selectAll' => $this->selectAll, 'ids' => $this->ids];
    }

    /**
     * An explicit selection without any id selects nobody.
     */
    public function isEmpty(): bool
    {
        return ! $this->selectAll && $this->ids === [];
    }

    /**
     * The export's query with the user's scope and salary rules applied
     * (ids outside the scope are dropped by the filters).
     */
    public function query(EmployeeVisibility $visibility): EmployeeSearchQuery
    {
        $query = $visibility->query($this->filters);

        return $this->selectAll ? $query->exceptIds($this->ids) : $query->onlyIds($this->ids);
    }

    /**
     * Employee numbers sent as one comma-separated field ("1,2,3"; the export form
     * does this because PHP drops request fields beyond max_input_vars, so thousands
     * of `ids[]` inputs would silently lose ids) or, for older clients, as an array.
     * Anything that is not a positive integer is ignored.
     *
     * @return int[]
     */
    public static function idList(mixed $value): array
    {
        $values = is_string($value) ? explode(',', $value) : (array) $value;

        $ids = [];
        foreach ($values as $id) {
            $id = is_string($id) || is_int($id) ? trim((string) $id) : '';

            if (ctype_digit($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Only scalar filter values are kept (EmployeeFilters ignores anything else anyway).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, scalar>
     */
    private static function scalars(array $values): array
    {
        return array_filter($values, fn ($value) => is_scalar($value));
    }
}
