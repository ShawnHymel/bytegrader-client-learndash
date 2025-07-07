# ByteGrader Client for LearnDash

A WordPress plugin that integrates [ByteGrader autograding service](https://github.com/ShawnHymel/bytegrader) with LearnDash LMS for automated code assessment.

## Features

- Project file submission through LearnDash quizzes
- Automated grading via ByteGrader service
- Progress tracking and completion management
- Drag-and-drop file upload interface

## Installation

### Method 1: WordPress Admin Upload (Recommended)

1. Download the latest release as a ZIP file from the [Releases page](https://github.com/ShawnHymel/bytegrader-client-learndash/releases)
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Choose the downloaded ZIP file and click **Install Now**
4. Activate the plugin

### Method 2: Manual Installation

1. Download and extract the plugin files
2. Upload the `bytegrader-client-learndash` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' screen in WordPress

## Debug Mode

ByteGrader Client logs will be enabled by setting these flags in `wp-config.php` (in the *For developers* section):

```php
define('BGCLD_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Debug logs will appear in `/wp-content/debug.log` and show ByteGrader submission processing, grading results, and any errors.

> **Note:** You can also set `define('WP_DEBUG', true);` if you want to enable WordPress-wide debugging.

## License

All code, unless otherwise specified, is subject to the [MIT License](https://opensource.org/license/mit). See the [LICENSE](/LICENSE) file for more details.