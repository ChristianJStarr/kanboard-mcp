# Kanboard Model Context Protocol (MCP) Plugin

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Kanboard](https://img.shields.io/badge/kanboard-1.2.0+-green.svg)](https://kanboard.org/)
[![MCP](https://img.shields.io/badge/MCP-2025--11--25-orange.svg)](https://modelcontextprotocol.io/)

**The most comprehensive Kanboard.org integration for AI assistants and automation tools.**

Transform your Kanboard into an AI-powered project management powerhouse! This plugin enables complete control over Kanboard through the Model Context Protocol (MCP), allowing AI assistants like Cursor, Claude, and other MCP clients to manage your projects through natural language commands.

## Features

- **Per-user Token Authentication**: Every Kanboard user gets their own MCP token that grants access only to their own boards and nothing else.
- **Per-user endpoint gateway**: Each user turns on only the tools and resources their role allows. An admin does not edit another person's toggles.
- **Role-Aware Authorization**: Read, write, or manage-board operations are enforced using each user's actual Kanboard project role (viewer / member / manager).
- **AI Assistant Ready**: Optimized for Cursor, Claude, and other AI development tools.
- **Full CRUD Operations**: Create, read, update, and delete across all Kanboard entities you have permission for.
- **Workflow Management**: Complete control over columns, swimlanes, and categories (project managers only).
- **Real-time Operations**: Instant project updates through natural language.
- **JSON-RPC 2.0 Compliant**: Fully compliant with MCP specification v2025-11-25.

## Complete Tool Suite (26 Tools)

### Project & Task Management (8 Tools)
| Tool | Description | Example Usage |
|------|-------------|---------------|
| `get_projects` | List your visible projects | *"Show me all my projects"* |
| `create_project` | Create new projects (admin/manager only) | *"Create a new project called Mobile App"* |
| `get_tasks` | Get project tasks | *"List all tasks in the Marketing project"* |
| `create_task` | Create new tasks | *"Add a task to implement user authentication"* |
| `update_task` | Modify existing tasks | *"Update the login task description"* |
| `delete_task` | Remove tasks | *"Delete the obsolete testing task"* |
| `get_task_details` | Get detailed task info | *"Show me full details for task #123"* |
| `move_task` | Move tasks between columns | *"Move the API task to Done column"* |

### Column Management (4 Tools) — project manager only
| Tool | Description | Example Usage |
|------|-------------|---------------|
| `get_columns` | List project columns | *"Show me all columns in this project"* |
| `create_column` | Add new columns | *"Create a Testing column with 5 task limit"* |
| `update_column` | Modify column settings | *"Change the Review column limit to 3 tasks"* |
| `delete_column` | Remove columns | *"Delete the unused Draft column"* |
| `reorder_columns` | Change column positions | *"Move Testing column before Done"* |

### Category Management (4 Tools) — project manager only
| Tool | Description | Example Usage |
|------|-------------|---------------|
| `get_categories` | List project categories | *"Show me all task categories"* |
| `create_category` | Add task categories | *"Create a Bug Fixes category"* |
| `update_category` | Modify categories | *"Rename Bug Fixes to Critical Issues"* |
| `delete_category` | Remove categories | *"Delete the unused category"* |

### Swimlane Management (4 Tools) — project manager only
| Tool | Description | Example Usage |
|------|-------------|---------------|
| `get_swimlanes` | List project swimlanes | *"Show me all team swimlanes"* |
| `create_swimlane` | Add team swimlanes | *"Create a Frontend Team swimlane"* |
| `update_swimlane` | Modify swimlanes | *"Rename Mobile Team to Cross-Platform Team"* |
| `delete_swimlane` | Remove swimlanes | *"Delete the inactive team swimlane"* |

### User & Assignment Management (6 Tools)
| Tool | Description | Example Usage |
|------|-------------|---------------|
| `get_users` | List users you can interact with | *"Show me all team members"* |
| `assign_task` | Assign tasks to project members | *"Assign the API task to John"* |
| `set_task_due_date` | Set task deadlines | *"Set due date for login task to next Friday"* |
| `add_task_comment` | Add task comments | *"Add comment about testing requirements"* |
| `get_task_comments` | Get task comments | *"Show all comments on this task"* |

## Quick Start

### Installation

1. **Download & Extract**: Copy the `ModelContextProtocol` folder to your Kanboard `plugins/` directory.
2. **Activate Plugin**: Access Kanboard as administrator → **Settings** → **Plugins** → Enable MCP Plugin.
3. **Get your token**: Go to **Settings** → **Integrations** (admins) **or** your user profile → **Integrations** (all users). Click **Autogenerar** (or **Generate Token** on first use).
4. **Copy the MCP configuration**: The token is shown **once**. Copy the **Authorization header** client block (server URL **without** a token, plus `headers.Authorization: Bearer …`). A URL that embeds `&token=` is also shown for older clients; prefer the header.

> Each user generates their own token independently. An admin looking at another user's profile does **not** see that user's token.

### Connecting an MCP client

**Recommended (spec-compliant):** send the token as `Authorization: Bearer`. The URL must **not** include the token.

```json
{
  "mcpServers": {
    "kanboard": {
      "url": "https://your-kanboard.com/?controller=ModelContextProtocolController&action=handle&plugin=ModelContextProtocol",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

**Compatibility:** some clients only accept a URL. That form puts the secret in the query string:

```
https://your-kanboard.com/?controller=ModelContextProtocolController&action=handle&plugin=ModelContextProtocol&token=YOUR_TOKEN
```

Web servers and reverse proxies often write the full request URL, including `?token=`, to access logs. Do not treat this path as private.

Administrators can check **Disable token in URL (query string)** on **Settings → Integrations**. While that setting is on, `?token=` is ignored and only `Authorization: Bearer` authenticates. The compatibility block stays visible so you can see why an old URL stopped working.

## AI Assistant Integration

### Cursor IDE Integration
1. Copy your MCP configuration block from the integrations page.
2. Paste it into Cursor's MCP settings (`.cursor/mcp.json` or settings UI).
3. Start managing your Kanboard boards through natural language in your IDE.

### Claude Desktop Integration
1. Copy the configuration block from the integrations page.
2. Paste it into Claude Desktop's MCP server configuration (`claude_desktop_config.json`).
3. Manage your projects while chatting with Claude.

### Custom MCP Clients
The plugin works with any MCP-compatible client and negotiates protocol versions up to 2025-11-25.

## Security

### Per-user Tokens
- Each Kanboard user has **one active MCP token**. It identifies that user on every request.
- Tokens are 64 hex characters (256 bits) generated with `random_bytes`.
- Clicking **Autogenerar** immediately invalidates the old token and issues a new one.
- When a user account is deactivated in Kanboard, their token stops working immediately.

### Scope Enforcement
Every tool call is authorized against the token user's real Kanboard permissions:

| Role | What the MCP token grants |
|------|--------------------------|
| Project Viewer | Read-only: `get_tasks`, `get_task_details`, `get_columns`, `get_categories`, `get_swimlanes`, `get_task_comments` |
| Project Member | Viewer rights plus task create/update/move/comment/assign/due-date. Delete only tasks they created. |
| Custom project role | Same task access as a member, minus that role's Kanboard restrictions. |
| Project Manager | Member rights plus delete any task, and full column/category/swimlane CRUD. |
| App Admin | Project Manager on every active project. Also `create_project` and `get_users` across all users. |
| App Manager | Can `create_project`. Project access follows normal membership. |

- `get_projects` and `kanboard://projects` return **only** the boards the token user can see. The project public-access `token` is omitted.
- `get_users` / `kanboard://users` return only `id`, `username`, `name` — no password hashes, API tokens, or 2FA secrets.
- Object IDs from other users' projects return a generic `Forbidden` error to prevent oracle attacks.
- `get_task_comments` returns comment id, task id, user id, username, name, body, dates, and visibility — not email, avatar paths, or other profile PII.
- `Referrer-Policy: no-referrer` is sent on every MCP response so the token is not forwarded to third-party origins via Referer. That does **not** stop your own web server or proxy from logging a `?token=` URL.

### Token Transport
- **Preferred:** `Authorization: Bearer YOUR_TOKEN`. Also accepted from `REDIRECT_HTTP_AUTHORIZATION` when PHP-CGI/rewrite does not populate `HTTP_AUTHORIZATION`.
- **Compatibility:** the `token` query-string parameter, unless an administrator has disabled URL tokens.
- If URL tokens are allowed and both header and query-string tokens are present and they differ, the request is rejected.
- If URL tokens are disabled, `$_GET['token']` is ignored. A valid Bearer header still authenticates.

### Endpoint gateway

Authentication identifies the user. The gateway chooses which role-allowed endpoints are on for that user's own token.

The integrations page has a second form, separate from **Autogenerar** and from Kanboard's own settings form. It lists only the endpoints `allowsCapability` grants to the signed-in user. Saving stores `enabled=1` or `enabled=0` for each of those endpoints. Rows are never deleted, and regenerating a token does not remove them. An administrator viewing another profile does not see or edit that person's gateway; the form is always the session user, the same as the token panel.

- Tokens that already existed when the instance was upgraded keep their write endpoints on. The schema backfill inserts `enabled=1` for every write-class catalog endpoint for each existing `mcp_tokens` user. Read endpoints stay on without inserted rows.
- Tokens created after the upgrade start with reads on and writes off until that user saves the form. A missing grant row means the catalog default.

What a role can see when the endpoint is enabled:

| Role | Listed when enabled |
|------|---------------------|
| Project viewer | The 8 read tools and the two read resources |
| Project member | Viewer endpoints, plus task create/update/move/assign/due-date/comment and delete |
| Project manager | Member endpoints, plus board tools (columns, categories, swimlanes) |
| App manager | The 8 read tools, the two read resources, and `create_project`. Board tools stay hidden unless they are also a project manager or an app admin. A project manager who is not an app manager does not get `create_project`. |
| App admin | All 26 tools and both resources on their own token, once those endpoints are enabled |

A fresh app-admin token shows the read endpoints until writes are turned on. An admin whose token existed before the upgrade still sees writes, because the backfill left them enabled.

`initialize` keeps `listChanged` false for tools and resources, so clients should reconnect to refresh `tools/list` after a save. `tools/call` still enforces the toggle immediately. A role-allowed endpoint that is switched off is omitted from the list and returns a tool result whose text is exactly `Forbidden`, including when the arguments are invalid. A name the role cannot use, and any unknown name, both return JSON-RPC `-32602` with message `Unknown tool: {name}`. A disabled resource reads as `-32002` Resource not found with `data.uri`, the same as an unknown URI. Turning a hidden tool on in the database does not make it callable.

## Technical Specifications

### Requirements
- **Kanboard**: 1.2.0 or higher
- **PHP**: 7.4 or higher (uses typed properties and named arguments)
- **Database**: MySQL, PostgreSQL, or SQLite

### Protocol Compliance
- **MCP Version**: 2025-11-25 (with backward negotiation to 2024-11-05)
- **Transport**: Streamable HTTP with JSON-RPC 2.0
- **Capabilities**: Tools, Resources

### API Endpoints
- **Main Handler**: `/?controller=ModelContextProtocolController&action=handle&plugin=ModelContextProtocol`
- **Authentication**: Per-user bearer token (`Authorization: Bearer`, optionally `?token=` when URL tokens are allowed)
- **Content-Type**: `application/json`
- **Methods**: POST for all MCP operations

## Changelog

### v1.2.3
- **URL tokens and comment PII**: the gateway branch includes the 1.1.4 Integrations header config, the admin disable-URL-token setting, CGI Authorization, and comment privacy fixes.

### v1.2.2
- **Review fixes**: the gateway branch includes the 1.1.3 authorization fixes. Schema version 4 removes any leftover `token_plain` value and then creates the endpoint grants.

### v1.2.1
- **Integrations submit**: Generate Token and Save access post forms rendered outside Kanboard's settings form, so one creates a token and the other saves grants. Both redirects return to the core integrations page and do not name this plugin.

### v1.2.0
- **Endpoint gateway**: Each user can enable or disable the MCP endpoints their role allows. Old tokens keep writes on via the migration backfill. New tokens start with writes off.
- **Unknown versus forbidden**: A role-hidden or unknown tool returns `Unknown tool`. A role-allowed endpoint that is off returns `Forbidden` and is not dispatched.
- **List refresh**: `listChanged` stays false. Clients should reconnect so `tools/list` matches the toggles. `tools/call` enforces the switch without waiting for that refresh.

### v1.1.4
- **Header configuration**: Integrations shows the spec-compliant MCP client block (URL without a token + `Authorization: Bearer`) and a compatibility URL-with-token block, with a warning that query-string tokens can appear in access logs.
- **Disable URL tokens**: Administrators can reject `?token=` from Settings → Integrations. Header authentication is unchanged. The setting survives token rotation.
- **CGI Authorization**: Bearer tokens are read from `HTTP_AUTHORIZATION`, then `REDIRECT_HTTP_AUTHORIZATION`.
- **Comment privacy**: `get_task_comments` no longer returns email or avatar_path.

### v1.1.3
- **Comment visibility**: `get_task_comments` follows Kanboard's application-role visibility rules for the token user.
- **Project creation**: `create_project` adds the token user as the project owner and manager.
- **Token storage**: only the SHA-256 hash is stored. The plaintext is shown once after generation.
- **Custom roles**: a custom project role can change tasks, except where that role has a Kanboard restriction.
- **Project payload**: project listings no longer include the public-access token.
- **MySQL upgrade**: replacing `mcp_tokens` can be run again if the table was already dropped.

### v1.1.2
- **Integrations submit**: Generate Token posts a form rendered outside Kanboard's settings form, so the button creates a token instead of saving settings. The redirect returns to the core integrations page and does not name this plugin.

### v1.1.1
- **Integrations form CSRF**: Token rotation accepts the reusable form token and reads the posted redirect target from the body, so the Autogenerar form works.
- **Admin user listing**: `get_users` queries active users for `id`, `username`, and `name`. The previous lookup does not exist on Kanboard 1.2.45.

### v1.1.0
- **Per-user tokens**: Each user now has their own MCP token scoped to their boards and role.
- **Autogenerar button**: Token rotation available to every user from Settings → Integrations or their profile.
- **Role enforcement**: All 26 tools and both resources enforce Kanboard project roles on every call.
- **User data privacy**: `get_users` no longer exposes password hashes, API tokens, or 2FA state.
- **Token transport hardening**: `Authorization: Bearer` header support; `Referrer-Policy: no-referrer`; conflicting dual-token requests rejected.

### v1.0.0 (Initial Release)
- 26 tools covering the full Kanboard project management surface.
- Administrative controls: columns, categories, swimlanes.
- AI assistant optimization for Cursor and Claude.

## Contributing

We welcome contributions! Please read our contributing guidelines and submit pull requests for:

- **New Tools**: Additional Kanboard functionality
- **Performance Improvements**: Optimization and caching
- **Documentation**: Examples and use cases
- **Bug Fixes**: Issue resolution and stability

## Support

- **Documentation**: Comprehensive examples in this README
- **Issues**: [GitHub Issues](https://github.com/ChristianJStarr/kanboard-mcp/issues)
- **Community**: [Kanboard Community](https://kanboard.org/community)

## License

This plugin is released under the MIT License - see [LICENSE](LICENSE) file for details.
