# ByteGrader Client for LearnDash

A WordPress plugin that integrates [ByteGrader autograding service](https://github.com/ShawnHymel/bytegrader) with LearnDash LMS for automated code assessment.

## Features

* Project file submission through LearnDash quizzes
* Automated grading via ByteGrader service
* Progress tracking and completion management
* Drag-and-drop file upload interface
* Student must keep browser tab open while grading occurs

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

## Using the ByteGrader Client for LearnDash (BGCLD)

### Configure BGCLD

1. In your WordPress admin dashboard, go to **Settings > ByteGrader**
2. Add the URL for your ByteGrader server (note: ByteGrader enforces TLS, so you must use `https://`)
3. Add the API Key for your ByteGrader server 
4. Click **Save Settings**
5. Click **Test Connection**, and you should see the server *version* and *config* in the response

### Create a Project Submission Page

BGCLD works by hijacking a LearnDash quiz page and showing a project submission form instead. To get this to work, we need to create a dummy quiz and then tell BGCLD to take over the page.

1. In your WordPress admin dashboard, go to **LearnDash LMS > Quizzes**
2. Click **Add New Quiz**
3. On the *Quiz page* tab, give your quiz a title (used to find your quiz in the admin dashboard)
4. Note that content you add on the quiz page will not be shown to the student
5. Scroll down to the bottom of the page, change *Quiz Type* to **Project Submission Quiz** (this is how BGCLD knows to hijack the page)
6. Fill out the **Max File Size** and **Assignment ID** (which should match the assignment ID you wish to use in your ByteGrader server)
7. Click on the **Builder** tab
8. Add a single dummy question (it will not be shown to the student, but LearnDeash requires at least one question for quizzes to work)
    1. Assign some points (e.g. 1 point) to the question (You must do this, as LearnDash requires points assigned to questions)
    2. Fill out the rest of the question, including a dummy answer
    3. Save your quiz
9. Add the quiz to your course
10. To test: browse to the quiz page and try submitting a project (.zip)

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

## Release Process

> **Note**: The *main* branch is not protected, as I'm the only dev right now

1. Choose appropriate version number using [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
2. Update **bytegrader-client-learndash.php** in the following places in :
    1. Header: `Version: X.Y.Z`
    2. Constant: `define('BGCLD_VERSION', '0.8.0');`
    3. Make sure that the ByteGrader version compatibility is up to date
3. Update **VERSION** file with new version number
4. Update **CHANGELOG.md**, assign new version number to the section
5. Commit changes directly to main (you must be on the maintainers bypass list to push to main):

```sh
git add --all
git commit -m "Prepare release vX.Y.Z"`
git push origin main
```

6. Create and push tag:

```sh
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
```

7. Create a GitHub release:

```sh
gh release create vX.Y.Z --title "vX.Y.Z" --notes "See [CHANGELOG.md](CHANGELOG.md) for release details."
```

## Todo

* Store job IDs to WP backend so that students can retrieve grade later if they close their browser window
* Implement cleanup strategy to remove old job IDs from backend

## License

All code, unless otherwise specified, is subject to the [MIT License](https://opensource.org/license/mit). See the [LICENSE](/LICENSE) file for more details.