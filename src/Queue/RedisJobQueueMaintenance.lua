local kUnclaimed, kClaimed, kAbandoned, kDelayed, kAttempts, kData = unpack(KEYS)
local now, claimTTL, maxTries, pruneAge, limit =
	tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3]), tonumber(ARGV[4]), tonumber(ARGV[5])
local released, recycled, abandoned, pruned = 0, 0, 0, 0
-- Release delayed jobs that are due
for _, id in ipairs(redis.call('zRangeByScore', kDelayed, 0, now, 'LIMIT', 0, limit)) do
	redis.call('zRem', kDelayed, id)
	redis.call('lPush', kUnclaimed, id)
	released = released + 1
end
-- Recycle or abandon jobs with expired claims
if claimTTL > 0 then
	for _, id in ipairs(redis.call('zRangeByScore', kClaimed, 0, now - claimTTL, 'LIMIT', 0, limit)) do
		redis.call('zRem', kClaimed, id)
		if tonumber(redis.call('hGet', kAttempts, id) or 0) < maxTries then
			redis.call('lPush', kUnclaimed, id)
			recycled = recycled + 1
		else
			redis.call('zAdd', kAbandoned, now, id)
			abandoned = abandoned + 1
		end
	end
end
-- Delete old abandoned jobs
if pruneAge > 0 then
	for _, id in ipairs(redis.call('zRangeByScore', kAbandoned, 0, now - pruneAge, 'LIMIT', 0, limit)) do
		redis.call('zRem', kAbandoned, id)
		redis.call('hDel', kAttempts, id)
		redis.call('hDel', kData, id)
		pruned = pruned + 1
	end
end
return { released, recycled, abandoned, pruned }
