# Continue this Claude Code session from your iPhone

The session lives on this Mac. Your phone is just a remote screen — you SSH in
and resume it.

## One-time setup

### On the Mac
1. System Settings → General → Sharing → turn on **Remote Login**.
   (Optionally set "Allow access for: Only these users" → your user `s`.)

### On the iPhone
2. Install an SSH app — **Termius** (free) or **Blink Shell**.
3. Add a new host:
   - **Host:** `Seans-Macbook-Pro-2.local`  (same Wi-Fi)
   - **Port:** `22`
   - **Username:** `s`
   - **Password:** your Mac login password

## Each time — resume this chat
Once connected over SSH:

```sh
cd /Users/s/GIT/websitetest
claude --continue        # resumes the most recent conversation here (this one)
# or:  claude --resume   # pick a specific past session from a list
```

## Work from anywhere (not just home Wi-Fi) — optional
The `.local` name and `192.168.x` IP only work on your home network. To reach the
Mac over cellular:

1. Install **Tailscale** on the Mac and the iPhone; sign in to the same account on both.
2. Use the Mac's Tailscale name (e.g. `seans-macbook-pro-2.tailnet.ts.net`) as the
   **Host** in Termius instead of the `.local` name.
3. (Tailscale keeps SSH on a private mesh — nothing is exposed to the public internet.)

## Connection details (this Mac)
- User: `s`
- Local hostname: `Seans-Macbook-Pro-2.local`
- Wi-Fi IP right now: `192.168.0.183` (can change after reboots — prefer the hostname)
- Project: `/Users/s/GIT/websitetest`
