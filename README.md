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

## Installation
1. Clone this repository.
    - `git clone git@github.com:Automattic/team51-cli.git`
1. Make sure [Composer](https://getcomposer.org/) is installed.
    - `brew install composer`
1. Run `cd team51-cli` to change directory to the cloned repository.
1. From the cloned repository, run `./install-osx`. First off, we know the fruit company has changed the name of the desktop operating system. Secondly, there's probably nothing fruit company specific about this install script. We could have called it `./install-posix`, but nobody's perfect.
1. Open 1Password and download the `config.json` file from a secure vault named `Team51-CLI Config File` to the root of the repository.
1. To verify the tool was installed successfully, you can run `team51` from your Terminal

## Usage
This CLI tool is self-documenting. You can view a list of available commands with `team51 list`.
You can then do `team51 <command-name> --help`.

A copy of that documentation possibly will land here in the future if it's useful.

## Troubleshooting

### `composer: command not found`
If you get the error `./install-osx: line 2: composer: command not found`, please run `brew install composer` to install Composer on your computer.

### `no such file or directory: ./install-osx`
Most likely you are not inside the team51 directory. Try with `cd team51-cli`. If that doesn't work, verify where you are located by running `pwd` and then use the *change directory* command (`cd`) to navigate to the team51-cli directory we cloned in the steps above.

### `Warning: file_get_contents: failed to open stream`
```
Warning: file_get_contents(/Users/.../team51-cli/config.json): failed to open stream: No such file or directory in /Users/.../team51-cli/src/helpers/config-loader.php on line 5

Config file couldn't be read. Aborting!
```
To fix this issue, open 1Password and search for a file called `config.json`. Download it and move it inside the `team51-cli` directory.

### `failed to open stream: Too many open files`

For certain commands involving the use of PHP workers threads, you might need to increase the `ulimit` on your local computer. To increase this number, you can run on the terminal: `ulimit -n 4096`.

A particular case where this might be needed is when using the `remove-user` command. If you get a `PHP Warning:` `failed to open stream: Too many open files...` try upgrading the ulimit as described above.