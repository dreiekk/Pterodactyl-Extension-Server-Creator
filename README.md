# Server Creator for Pterodactyl

[![Release](https://img.shields.io/github/v/release/CCBlueX/Pterodactyl-Extension-Server-Creator?style=flat-square)](https://github.com/CCBlueX/Pterodactyl-Extension-Server-Creator/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/CCBlueX/Pterodactyl-Extension-Server-Creator/total?style=flat-square)](https://github.com/CCBlueX/Pterodactyl-Extension-Server-Creator/releases)
[![Pterodactyl](https://img.shields.io/badge/Pterodactyl-2.0-0e4688?style=flat-square)](https://pterodactyl.io)
[![License](https://img.shields.io/github/license/CCBlueX/Pterodactyl-Extension-Server-Creator?style=flat-square)](LICENSE)

Let users create their own servers within a quota, and duplicate servers in one click.

![Create a server](docs/screenshots/create.png)

## Install

1. Download `server-creator-<version>.pteroext` from the [latest release](https://github.com/CCBlueX/Pterodactyl-Extension-Server-Creator/releases/latest).
2. Install and enable it under **Admin → Extensions**.
3. Under **Admin → Server Creator**, add a game (or pick one under **Popular games**) and give users a quota.

## Features

- **Game picker**: users pick a game, then size it with sliders in GiB and cores, or click the size to type it. Shows what's left and marks a recommended size.
- **Popular games**: Counter-Strike 2, Valheim, Terraria, Palworld, Project Zomboid and more come with sizes and artwork. Missing eggs are imported from the Pterodactyl egg catalog.
- **Game ports**: configure ordered default ports, including non-consecutive ports; when unavailable, the same spacing is used with other free ports. Extra ports can be written into egg variables.
- **Manage ports**: server owners can select available allocations on their server's node and choose the primary port, within the allocation limit and game port rules. Stop the server before making changes.
- **Port ranges**: limit a game to ports such as `25565-25600`, so each game gets its own ports on a shared node.
- **Quotas**: limit servers, memory, disk, CPU, databases, backups and ports, for everyone or per user.
- **Resources page**: owners resize, switch to another game and delete (opt-in) on their server's Resources page. A switch brings the new game's egg, limits and ports, and egg changes from other extensions keep the game and ports in step.
- **Safe placement**: new servers only go to nodes with room, in the locations you choose.
- **Duplicate**: copy any server from its admin page, optionally with all its files.

![Resources page](docs/screenshots/resources.png)

![Admin games and quotas](docs/screenshots/admin.png)

Users only see the **Create server** button once their quota allows at least one server. The default quota is 0.

Popular game presets include defaults such as Minecraft's 25565, Terraria's 7777 and Barotrauma's 27015 and 27016. Administrators can configure default ports for any game; the first is primary, and all must be free on the same node and IP. Port ranges still apply. If the defaults are unavailable, another matching set is selected. An egg must support the configured port variables to apply secondary ports automatically; otherwise configure the game's ports in its own files. For example, the standard Barotrauma egg does not expose port variables.

---

Inspired by the Pelican plugins User Creatable Servers, User Game Server Creator, Egg Images and Duplicate Server.
