# ByteGrader Client for LearnDash

A WordPress plugin that integrates [ByteGrader autograding service](https://github.com/ShawnHymel/bytegrader) with LearnDash LMS for automated code assessment.

## Features

- Project file submission through LearnDash quizzes
- Automated grading via ByteGrader service
- Progress tracking and completion management
- Drag-and-drop file upload interface
- Student must keep browser tab open while grading occurs

## Installation

### Option 1: WordPress Admin Upload (Recommended)

1. Download the latest release as a ZIP file from the [Releases page](https://github.com/ShawnHymel/bytegrader-client-learndash/releases)
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Choose the downloaded ZIP file and click **Install Now**
4. Activate the plugin

### Option 2: Manual Installation

1. Download and extract the plugin files
2. Upload the `bytegrader-client-learndash` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' screen in WordPress

## Compatibility

### ByteGrader Server Compatibility

This plugin is compatible with the following ByteGrader server versions:

| Client Version | Compatible Server Versions | Tested With | Notes |
|:--------------:|:--------------------------:|:-----------:|:-----:|
| 0.8.1          | 0.8.1 - 0.8.x              | 0.8.2       | Initial release |

### Checking Compatibility

1. Go to **Settings > ByteGrader** in your WordPress admin
2. Configure your server URL and API key
3. Click **Test Connection** to verify compatibility

The test will show:
* ✅ **Fully Compatible**: Server version is tested and supported
* ⚠️ **Compatible (Warning)**: Server version should work but isn't fully tested
* ❌ **Incompatible**: Server version is too old or too new

### Upgrading

When upgrading either component:

1. **Server First**: Always upgrade the ByteGrader server before the client plugin
2. **Check Compatibility**: Use the connection test after any upgrade
3. **Staged Rollout**: Test in a development environment first

### API Endpoints

This plugin relies on these ByteGrader API endpoints:
* `POST /submit` - File submission
* `GET /status/{job_id}` - Job status checking  
* `GET /queue` - Queue information
* `GET /config` - Server configuration (admin)
* `GET /version` - Server version (admin)

Breaking changes to these endpoints will require plugin updates.

## Debug Mode

ByteGrader Client logs will be enabled by setting these flags in `wp-config.php` (in the *For developers* section):

```php
define('BGCLD_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Debug logs will appear in `/wp-content/debug.log` and show ByteGrader submission processing, grading results, and any errors.

> **Note:** You can also set `define('WP_DEBUG', true);` if you want to enable WordPress-wide debugging.

## Todo

* Store job IDs to WP backend so that students can retrieve grade later if they close their browser window
* Implement cleanup strategy to remove old job IDs from backend

## License

All code, unless otherwise specified, is subject to the [MIT License](https://opensource.org/license/mit). See the [LICENSE](/LICENSE) file for more details.