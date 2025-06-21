# Kanboard Model Context Protocol (MCP) Plugin

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Kanboard](https://img.shields.io/badge/kanboard-1.2.0+-green.svg)](https://kanboard.org/)
[![MCP](https://img.shields.io/badge/MCP-2025--18--06-orange.svg)](https://modelcontextprotocol.io/)

**The most comprehensive Kanboard.org integration for AI assistants and automation tools.**

Transform your Kanboard into an AI-powered project management powerhouse! This plugin enables complete control over Kanboard through the Model Context Protocol (MCP), allowing AI assistants like Cursor, Claude, and other MCP clients to manage your projects through natural language commands.

## Features

- **Complete Enterprise Control**: 26 powerful tools covering every aspect of project management
- **Secure Token Authentication**: Enterprise-grade security with token-based access
- **AI Assistant Ready**: Optimized for Cursor, Claude, and other AI development tools
- **Full CRUD Operations**: Create, read, update, and delete across all Kanboard entities
- **Workflow Management**: Complete control over columns, swimlanes, and categories
- **Real-time Operations**: Instant project updates through natural language
- **JSON-RPC 2.0 Compliant**: Fully compliant with MCP specification v2025-18-06

## Complete Tool Suite (26 Tools)

### Project & Task Management (8 Tools)
| Tool | Description | Example Usage |
|------|-------------|---------------|
| `get_projects` | List all projects | *"Show me all projects"* |
| `create_project` | Create new projects | *"Create a new project called Mobile App"* |
| `get_tasks` | Get project tasks | *"List all tasks in the Marketing project"* |
| `create_task` | Create new tasks | *"Add a task to implement user authentication"* |
| `update_task` | Modify existing tasks | *"Update the login task description"* |
| `delete_task` | Remove tasks | *"Delete the obsolete testing task"* |
| `get_task_details` | Get detailed task info | *"Show me full details for task #123"* |
| `move_task` | Move tasks between columns | *"Move the API task to Done column"* |

### Column Management (4 Tools)
| Tool | Description | Example Usage |
|------|-------------|---------------|
| `get_columns` | List project columns | *"Show me all columns in this project"* |
| `create_column` | Add new columns | *"Create a Testing column with 5 task limit"* |
| `update_column` | Modify column settings | *"Change the Review column limit to 3 tasks"* |
| `delete_column` | Remove columns | *"Delete the unused Draft column"* |
| `reorder_columns` | Change column positions | *"Move Testing column before Done"* |

### Category Management (4 Tools) 
| Tool | Description | Example Usage |
|------|-------------|---------------|
| `get_categories` | List project categories | *"Show me all task categories"* |
| `create_category` | Add task categories | *"Create a Bug Fixes category"* |
| `update_category` | Modify categories | *"Rename Bug Fixes to Critical Issues"* |
| `delete_category` | Remove categories | *"Delete the unused category"* |

### Swimlane Management (4 Tools)
| Tool | Description | Example Usage |
|------|-------------|---------------|
| `get_swimlanes` | List project swimlanes | *"Show me all team swimlanes"* |
| `create_swimlane` | Add team swimlanes | *"Create a Frontend Team swimlane"* |
| `update_swimlane` | Modify swimlanes | *"Rename Mobile Team to Cross-Platform Team"* |
| `delete_swimlane` | Remove swimlanes | *"Delete the inactive team swimlane"* |

### User & Assignment Management (6 Tools)
| Tool | Description | Example Usage |
|------|-------------|---------------|
| `get_users` | List all system users | *"Show me all team members"* |
| `assign_task` | Assign tasks to users | *"Assign the API task to John"* |
| `set_task_due_date` | Set task deadlines | *"Set due date for login task to next Friday"* |
| `add_task_comment` | Add task comments | *"Add comment about testing requirements"* |
| `get_task_comments` | Get task comments | *"Show all comments on this task"* |

## Real-World Usage Examples

### For Software Development Teams
```
"Create a new project called Mobile Banking App with columns for Backlog, Development, Testing, and Production"

"Add a Bug Fixes category and create a Critical Issues swimlane for the mobile team"

"Move all authentication tasks to the Testing column and assign them to Sarah with due date next Monday"
```

### For Marketing Teams  
```
"Show me all tasks in the Campaign project and create a new Social Media category"

"Create a Content Review column with a 3-task limit and move all draft posts there"

"Add a comment to the blog post task about SEO requirements"
```

### For Project Managers
```
"List all projects and show me which ones have overdue tasks"

"Create swimlanes for Frontend, Backend, and QA teams in the Development project"

"Reorder columns to put Code Review between Development and Testing"
```

## Quick Start

### Installation

1. **Download & Extract**: Copy the `ModelContextProtocol` folder to your Kanboard `plugins/` directory
2. **Activate Plugin**: Access Kanboard as administrator → **Settings** → **Plugins** → Enable MCP Plugin
3. **Generate Token**: Go to **Settings** → **Integrations** → **Configure MCP Settings** → **Generate Token**
4. **Copy Server URL**: Use the generated MCP Server URL with your AI assistant

### MCP Server URL Format
```
https://your-kanboard.com/?controller=ModelContextProtocolController&action=handle&plugin=ModelContextProtocol&token=YOUR_TOKEN
```

## AI Assistant Integration

### Cursor IDE Integration
1. Copy your MCP Server URL
2. Add to Cursor's MCP settings
3. Start managing Kanboard through natural language in your IDE!

### Claude Desktop Integration
1. Add server configuration to Claude Desktop
2. Use the MCP URL as the server endpoint
3. Manage projects while chatting with Claude!

### Custom MCP Clients
The plugin works with any MCP-compatible client following the 2024-11-05 specification.

## Security Features

- **Token-Based Authentication**: Each token provides controlled access to your Kanboard instance
- **Secure Communication**: All requests require valid token authentication
- **User Context**: Operations maintain proper user attribution and permissions
- **Activity Logging**: All MCP operations are logged for audit trails
- **Token Rotation**: Generate new tokens to revoke old access

## Technical Specifications

### Requirements
- **Kanboard**: 1.2.0 or higher
- **PHP**: 7.0 or higher  
- **Database**: MySQL, PostgreSQL, or SQLite
- **Memory**: 128MB+ recommended for large projects

### Protocol Compliance
- **MCP Version**: 2024-11-05
- **Transport**: HTTP with JSON-RPC 2.0
- **Capabilities**: Tools, Resources, Experimental features
- **Error Handling**: Comprehensive error responses with debugging info

### API Endpoints
- **Main Handler**: `/?controller=ModelContextProtocolController&action=handle`
- **Authentication**: Token-based via URL parameter
- **Content-Type**: `application/json`
- **Methods**: POST for all MCP operations

## Enterprise Features

### Workflow Automation
- **Task Lifecycle Management**: Complete automation of task creation to completion
- **Team Coordination**: Automatic assignment and notification through swimlanes
- **Quality Control**: Column limits and category organization for workflow management

### Reporting & Analytics
- **Project Overview**: Instant access to project status and team workload
- **Task Tracking**: Detailed task information including comments and history
- **Team Performance**: User assignments and completion tracking

### Integration Capabilities
- **CI/CD Integration**: Automate task updates from build systems
- **Slack/Teams Bots**: Create chatbots that manage Kanboard through MCP
- **Custom Dashboards**: Build external tools that sync with Kanboard data

## Testing & Validation

The plugin includes comprehensive testing tools:

```bash
# Test basic connectivity
curl -X POST "YOUR_MCP_URL" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"ping","id":1}'

# Test tool discovery  
curl -X POST "YOUR_MCP_URL" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

## Contributing

We welcome contributions! Please read our contributing guidelines and submit pull requests for:

- **New Tools**: Additional Kanboard functionality
- **Performance Improvements**: Optimization and caching
- **Documentation**: Examples and use cases
- **Bug Fixes**: Issue resolution and stability

## Changelog

### v1.0.0 (Initial Release)
- **26 Complete Tools**: Full project management suite
- **Administrative Controls**: Column, category, and swimlane management  
- **Enterprise Security**: Enhanced token management
- **AI Assistant Optimization**: Optimized for Cursor and Claude

## Support

- **Documentation**: Comprehensive examples in this README
- **Issues**: [GitHub Issues](https://github.com/ChristianJStarr/kanboard-mcp/issues)
- **Community**: [Kanboard Community](https://kanboard.org/community)
- **Enterprise Support**: Available for large deployments

## License

This plugin is released under the MIT License - see [LICENSE](LICENSE) file for details.

---
