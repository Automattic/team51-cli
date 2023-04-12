# EXAMPLE_REPO_NAME

This repo is for EXAMPLE_REPO_NAME, powered by WordPress.

## Project Structure

- git is initialized in the `wp-content` directory
- The main `themes`, `plugins`, and `mu-plugins` directories are ignored
- Project-relevant themes and plugins that must be tracked are added as exceptions to the `.gitignore` file

## GitHub Workflow

1. Make your fix in a new branch.
1. Merge your `fix/` branch into the `develop` branch and test on the staging site.
1. If all looks good, make a PR and merge from your fix branch into `trunk`.

NOTE: While PRs are not required to be manually reviewed, we are happy to review any PR for any reason. Please ping us in Slack with a link to the PR.

## Deployment

- Prior to launch, during development, pushing to the `trunk` branch will automatically deploy to the in-progress site at https://EXAMPLE_REPO_PROD_URL
- Once this project is launched, pushing to the `trunk` branch will be reviewed and deployed to the production site by a member of the Special Projects Team (see GitHub workflow above)
- A new dev/staging site will then be created and pushing to the `develop` branch will then automatically deploy that dev/staging site at https://EXAMPLE_REPO_DEV_URL
