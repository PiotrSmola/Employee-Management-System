# REST API v1

Read-only JSON API over the employee directory. Base URL: `http://<host>/api/v1`
(Docker: `http://localhost:8000/api/v1`).

## Authentication

The API is a bulk data interface: it requires the `export-data` ability (admin, HR, manager).
Viewers cannot create tokens, and any token of a viewer is answered with 403.

Create a **personal access token** under *My account → API tokens* (name, expiry 30/90/365
days or never). The token is shown **once**; only its hash is stored. Send it as a bearer token:

```bash
export EMS_TOKEN='12|ems_9f8c...'
curl -H "Authorization: Bearer $EMS_TOKEN" -H "Accept: application/json" http://localhost:8000/api/v1/employees
```

- A token acts **as its owner**: same role, same manager department scope, same salary rules
  as the web directory (docs/AUTHORIZATION.md). Changing the user's role changes what the
  token can read.
- All tokens of a user are **revoked** when their password is changed or reset, their role
  changes, or the account is deactivated or deleted; create a new one afterwards. A web session
  (cookie) does **not** authenticate the API. Tokens have the `read` ability, which every
  endpoint requires.
- Up to 20 tokens per user. Creating and revoking tokens is audited (`data.api_token_created`,
  `data.api_token_revoked`); individual API requests are not.

## Rate limit

60 requests per minute per user (all tokens of a user share the limit; `API_RATE_LIMIT`), and
30 per minute per IP address for requests without a valid token (`API_GUEST_RATE_LIMIT`).
Responses carry `X-RateLimit-Limit` / `X-RateLimit-Remaining`; above the limit the API answers
`429` with a `Retry-After` header.

## Errors

Every error has the same shape:

```json
{"error": {"status": 404, "code": "not_found", "message": "Employee not found."}}
```

| Status | `code` | When |
|-------:|--------|------|
| 401 | `unauthenticated` | missing, unknown, revoked or expired token |
| 403 | `forbidden` | no `export-data` (viewers), deactivated account, token without `read`, employee outside a manager's department |
| 404 | `not_found` | unknown employee or endpoint |
| 405 | `method_not_allowed` | e.g. `POST` (the API is read-only) |
| 422 | `validation_failed` | invalid parameters; details in `error.fields` (`{"per_page": ["..."]}`) |
| 429 | `too_many_requests` | rate limit exceeded |
| 500 | `server_error` | unexpected error (details only in the server log) |

## `GET /employees`

The directory search. Parameters (all optional; the same names as the directory's URL):

| Parameter | Values |
|-----------|--------|
| `search` | name (first, last or full, substring) or an employee number (exact match) |
| `status` | `all` (default), `current`, `former` |
| `gender` | `M`, `F` |
| `department` | department code, e.g. `d005` (current department, or the last one of former employees) |
| `title` | job title (current, or the last one) |
| `salary_min`, `salary_max` | current salary bounds (only with salary access, otherwise 422) |
| `sort` | `emp_no` (default), `name`, `department`, `title`, `salary` (salary access only), `hire_date`, `gender` |
| `direction` | `asc` (default), `desc` |
| `page` | 1, 2, ... |
| `per_page` | 1-100, default 20 |

```bash
curl -H "Authorization: Bearer $EMS_TOKEN" \
  "http://localhost:8000/api/v1/employees?department=d005&status=current&sort=hire_date&direction=desc&per_page=50"
curl -H "Authorization: Bearer $EMS_TOKEN" "http://localhost:8000/api/v1/employees?search=10001"
```

```json
{
  "data": [
    {
      "emp_no": 10001, "first_name": "Georgi", "last_name": "Facello", "gender": "M",
      "hire_date": "1986-06-26", "status": "current", "department": "Development",
      "title": "Senior Engineer", "salary": 88958,
      "url": "http://localhost:8000/api/v1/employees/10001"
    }
  ],
  "meta": {
    "current_page": 1, "per_page": 20, "total": 300024, "last_page": 15002, "from": 1, "to": 20,
    "filters": {"search": null, "status": "all", "gender": null, "department": null, "title": null, "sort": "emp_no", "direction": "asc"}
  },
  "links": {"first": "...?page=1", "last": "...?page=15002", "prev": null, "next": "...?page=2"}
}
```

- Former employees have `"status": "former"` and their **last** department / title.
- `salary` (current salary) is present only with `view-salaries`, otherwise the key is missing
  everywhere. Every role with API access has it today; the rule applies should that change.
- `meta.filters` shows the filters actually applied - for a manager `department` is always
  their own department, whatever was requested.

## `GET /employees/{emp_no}`

One employee with the complete history. 404 for unknown numbers, 403 outside a manager's
department.

```bash
curl -H "Authorization: Bearer $EMS_TOKEN" http://localhost:8000/api/v1/employees/10001
```

```json
{
  "data": {
    "emp_no": 10001, "first_name": "Georgi", "last_name": "Facello", "birth_date": "1953-09-02",
    "gender": "M", "hire_date": "1986-06-26", "status": "current",
    "department": {"dept_no": "d005", "name": "Development"},
    "title": "Senior Engineer", "salary": 88958,
    "history": {
      "departments": [{"dept_no": "d005", "name": "Development", "from": "1986-06-26", "to": null}],
      "titles": [{"title": "Senior Engineer", "from": "1986-06-26", "to": null}],
      "salaries": [{"salary": 60117, "from": "1986-06-26", "to": "1987-06-26"}, {"salary": 88958, "from": "2002-06-22", "to": null}]
    }
  }
}
```

`"to": null` marks the current period (`9999-01-01` in the database). `department`,
`title` are the current ones (`null` for former employees). `salary` and `history.salaries`
only with salary access.

## `GET /departments`

```bash
curl -H "Authorization: Bearer $EMS_TOKEN" http://localhost:8000/api/v1/departments
```

```json
{"data": [{"dept_no": "d001", "name": "Marketing", "current_employees": 14842}]}
```

A manager only gets their own department.

## Implementation notes

- Routes: `routes/api.php` (prefix `/api/v1`, middleware `App\Http\Middleware\ThrottleApi`, which
  runs before authentication, `auth:sanctum`, `App\Http\Middleware\EnsureTokenUserIsActive`,
  the `read` ability and `can:export-data`); controllers in
  `App\Http\Controllers\Api\V1`. The list uses `EmployeeVisibility::query()` - the same
  query object as the directory - so new filters / scope rules apply to both.
- Error format: `App\Http\Api\ApiErrorRenderer` (registered for `/api/*` in
  `App\Providers\DataToolsServiceProvider`, which also defines the `api` rate limiter).
- Sanctum is configured for tokens only (`config/sanctum.php`: `guard => []`, token prefix `ems_`).
