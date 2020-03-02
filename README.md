# pidgbot
This is a simple IRC bot/client written in PHP using the sockets extension.

I couldn't find a good, well-functioning example of a PHP irc bot (perhaps for good reason). Against my better judgement, I decided to write one.

This is really a template which is intended to be extended into a useful bot.

The only built-in commands are:

* !uptime - returns uptime
* !join #channel - instruct bot to join a channel
* !part #channel - instruct bot to leave a channel
* !die - quits gracefully then dies

All commands (except !uptime) require the user's hostmask to match one of those stored in the $owners array.
