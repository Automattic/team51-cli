# Team51 CLI

## What even is this? 

Glad you asked. 

This is a small utility written by some fine folks at Automattic.com. It automates and standardizes provisioning new WordPress sites (on Pressable.com), connecting them to code repositories (on GitHub.com) and configuring deploy automation (via DeployHQ.com). It does a few other things, but that's the gist.

## How do I use this?

You probably don't. 

That said, with minimal work and especially if you're already using any of the three service providers we are, you can fork this repo and adapt it to your own needs. Or, perhaps you'll use this tool as inspiration to build something similar which automates a repeatable task you or your team are responsible for.

## May I contribute to this?

Probably not. 

This tool is fairly specific to our team's workflow...and we intend to keep it that way vs. abstracting it for anyone and everyone's needs. That said, we're open to hearing from you and feel free to reach us by opening an issue- please include a reference/link to your favorite song as a clue that you've read this =)

## Why is this named "Team51"

It's the nickname for our Special Projects team at Automattic.

## Anything else?

Be well, be kind, make things and set them free.

## Dependencies
- Composer (instructions below)
- GitHub CLI (`gh`) for certaing user related tasks

## Installation
1. Open the Terminal on your Mac and clone this repository by running:
    - `git clone git@github.com:Automattic/team51-cli.git`
    - It will ask you an SSH Passphrase, type it and hit Enter (if you're unsure what the passphrase is, try entering the same you'd use for the AutoProxxy)
1. Make sure [Composer](https://getcomposer.org/) is installed on your computer.
    - The easiest way to install composer is installing [brew](https://brew.sh/) first, and then running `brew install composer`
1. Now let's install the CLI! From the `team51-cli` directory, run `./install-osx`.
    - If you get an error `no such file or directory: ./install-osx`, try running `cd team51-cli` first.
1. Open 1Password and download the `config.json` file from a secure vault named `Team51-CLI Config File`. Place this file inside `team51-cli` directory.
1. To verify the tool was installed successfully, you can run `team51` from your Terminal

## Usage
This CLI tool is self-documenting. You can view a list of available commands with `team51 list`.
You can then do `team51 <command-name> --help`.

A copy of that documentation possibly will land here in the future if it's useful.

## Troubleshooting

### Before anything else
If you haven't used the CLI in a while and you're getting a lot of `Deprecated` notices or PHP `Fatal error`, try running `./install-osx` again to make sure all the dependencies are up to date. 

Remember: you need be inside the `team51-cli` directory in order to execute the install command.

### `no such file or directory: ./install-osx`
Most likely you are not inside the team51 directory. Try with `cd team51-cli`. If that doesn't work, verify where you are located by running `pwd` and then use the *change directory* command (`cd`) to navigate to the team51-cli directory we cloned in the steps above.

### `composer: command not found`
If you get the error `./install-osx: line 2: composer: command not found`, you can run `brew install composer` to install Composer on your computer.

### `brew: command not found`
If you don't have [brew](https://brew.sh/) yet, install it by executing this from your Terminal: `/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"` — Tip: you can use brew to install all sort of apps on your Mac. Give it [a try](https://formulae.brew.sh/cask/zoom)

### `Warning: file_get_contents: failed to open stream`
```
Warning: file_get_contents(/Users/.../team51-cli/config.json): failed to open stream: No such file or directory in /Users/.../team51-cli/src/helpers/config-loader.php on line 5

Config file couldn't be read. Aborting!
```
To fix this issue, open 1Password and search for a file called `config.json`. Download it and move it inside the `team51-cli` directory.

### `failed to open stream: Too many open files`

For certain commands involving the use of PHP workers threads, you might need to increase the `ulimit` on your local computer. To increase this number, you can run on the terminal: `ulimit -n 8192`.

A particular case where this might be needed is when using the `remove-user` command. If you get a `PHP Warning:` `failed to open stream: Too many open files...` try upgrading the ulimit as described above.

### `env: php: No such file or directory`

This is likely because you don't have PHP installed on your system. If you recently updated to MacOS Monterey, that could be the culprit as it doesn't come bundled with PHP anymore. If you have `brew` installed, it's easy to get PHP added by running the following command in the terminal: `brew install php@7.4 brew-php-switcher`. This will install the latest version of PHP 7.4 as well as a handy utility for switching between PHP versions if you need to.

You will then need to run `brew link php@7.4` so that PHP gets symlinked properly to your system's PHP files. Running `team51` now should work just fine.
