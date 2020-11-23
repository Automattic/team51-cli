# EXAMPLE_REPO_NAME

This repo is for EXAMPLE_REPO_NAME, powered by WordPress.

## Project Structure

- Git is initialized in the `wp-content` directory
- The main `themes`, `plugins`, and `mu-plugins` directories are ignored
- Project-relevant themes and plugins are added as exceptions to the `.gitignore` file so they will be tracked


If `mu-plugins` are needed for a project, create a `mu-plugins` directory and include a `mu-autoloader.php` file within that mu-plugins directory (`/mu-plugins/mu-autoloader.php`). In that `mu-autoloader.php` file, add the following contents:

```
<?php
/**
 * This file is for loading all mu-plugins within subfolders
 * where the PHP file name is exactly like the directory name + .php.
 *
 * Example: /mu-tools/mu-tools.php
 */

$dirs = glob(dirname(__FILE__) . '/*' , GLOB_ONLYDIR);

foreach($dirs as $dir) {
    if(file_exists($dir . DIRECTORY_SEPARATOR . basename($dir) . ".php")) {
        require($dir . DIRECTORY_SEPARATOR . basename($dir) . ".php");
    }
}
```

## GitHub Workflow

1. Make your fix in a new branch.
1. Merge your `fix/` branch into the `develop` branch and test on the staging site.
1. If all looks good, make a PR from your fix branch into `trunk`.
1. A member of the Special Projects Team will review, test, and merge it to the live site.

## Deployment

- Prior to launch, during development, pushing to the `trunk` branch will automatically deploy to the in-progress site at https://EXAMPLE_REPO_PROD_URL
- Once this project is launched, pushing to the `trunk` branch will be reviewed and deployed to the production site by a member of the Special Projects Team (see GitHub workflow above)
- A new dev/staging site will then be created and pushing to the `develop` branch will then automatically deploy that dev/staging site at https://EXAMPLE_REPO_DEV_URL