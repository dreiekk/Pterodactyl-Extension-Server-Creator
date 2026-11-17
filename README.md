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
- **Private catalog eggs**: administrators can grant individual trusted users egg import access on their Server Creator user page. They can search the official catalog, choose a port layout, and create their own servers with resource and feature limits within their quotas. Imported game profiles are private; revoking access blocks further creation and switching to them but leaves existing servers running. Imported eggs contain scripts and container images, so grant access only to trusted users.
- **Manage imported profiles**: administrators can view, edit, disable or delete private profiles from **Server Creator → Manage imported eggs**. Changes to limits and port rules also affect existing servers linked to the profile. Deleting a profile unlinks existing servers but leaves them and the underlying egg intact; a user with import permission can import it again.
- **Game ports**: configure ordered default ports, including non-consecutive ports; when unavailable, the same spacing is used with other free ports. Extra ports can be written into egg variables.
- **Manage ports**: server owners can select available allocations on their server's node and choose the primary port, within the allocation limit and game port rules. Stop the server before making changes.
- **Swap ports**: owners can exchange complete allocation sets and primary ports between two of their stopped servers on the same node, subject to each game's port rules and limits. Allocation notes move with their ports and may be visible to users of the other server. If each server has only a primary allocation, an additional free allocation on the node is needed temporarily.
- **Port ranges**: limit a game to ports such as `25565-25600`, so each game gets its own ports on a shared node.
- **Quotas**: limit servers, memory, disk, CPU, databases, backups and ports, for everyone or per user. Each total quota can also be set to unlimited; zero still allows none. Individual game limits and node capacity continue to apply.
- **Resources page**: owners resize, switch to another game and delete (opt-in) on their server's Resources page. A switch brings the new game's egg, limits and ports, and egg changes from other extensions keep the game and ports in step.
- **Safe placement**: new servers only go to nodes with room, in the locations you choose.
- **Duplicate**: copy any server from its admin page, optionally with all its files.
- **Audit log**: administrators can review successful Server Creator actions on a dedicated page linked from the Server Creator admin page, including catalog selections, server changes, duplication requests, and game, quota, location and catalog-permission changes. Entries retain actor and target names after deletion and do not contain startup-variable values. A file-copy request is logged when queued, not as proof that the asynchronous copy completed. The panel's separate extension settings and actions outside Server Creator are not included.

![Resources page](docs/screenshots/resources.png)

![Admin games and quotas](docs/screenshots/admin.png)

Users only see the **Create server** button once their quota allows at least one server. The default quota is 0.

Popular game presets include defaults such as Minecraft's 25565, Terraria's 7777 and Barotrauma's 27015 and 27016. Administrators can configure default ports for any game; the first is primary, and all must be free on the same node and IP. Port ranges still apply. If the defaults are unavailable, another matching set is selected. An egg must support the configured port variables to apply secondary ports automatically; otherwise configure the game's ports in its own files. For example, the standard Barotrauma egg does not expose port variables.

For private catalog imports, the catalog's listed default ports and their count are suggested when available. Check the catalog details and adjust the required ports and optional secondary port variables before importing; some ports require manual game configuration, and ambiguous or unavailable catalog data must be entered manually. Importing the same egg again retains the original profile's port settings, so existing servers' port rules do not change. Server creation and resizing still obey the user's total quota and node capacity. Database, backup and extra-port limits are selected per new server; switching games uses the destination game's configured feature limits.

---

Inspired by the Pelican plugins User Creatable Servers, User Game Server Creator, Egg Images and Duplicate Server.
