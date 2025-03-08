# gnuplot script by @tobozo copyleft (c+) Aug 2024
set title outputtitle # "Grouchabot Mastodon Reach vs Followers"

# graph colors
rank_color = "#000000"
reach_color = "#cc00cc"
# reach_waterline = 5000
rank_waterline = 10
followers_color = "#FF8888"
followers_avg_color = "#ccccff"
bg_altcolor1 = '#80eeeeee' # AARRGGBB
bg_altcolor2 = '#80fafafa' # AARRGGBB

# csv column numbers
rank_col=7
reach_col=6
reach_avg_col=12
followers_col=5
followers_avg_col=11
object_col=3
date_col = 1

set terminal pngcairo size width,height enhanced font 'Verdana,8'
set output outputfilename # 'cache/reach.png'
set datafile separator ","
set key autotitle columnhead box opaque top left # use the first line as title
#set key inside bottom center horizontal font "Verdana, 8" width 1.8

stats filename using ($6) nooutput

set xdata time # the X axis will be date/time data type
set timefmt '%Y-%m-%d' # how the date/time input must be parsed
set format x "%b\nw%W" # output format on the X axis

# date utils
dow(t)      = int(tm_wday(t)) ? tm_wday(t) : 7                               # day of week 1=Mon, ..., 7=Sun
week(t)     = int((11 + tm_yday(t) - dow(t))/7)                              # "raw"week of year
wday(d,m,y) = tm_wday(strptime("%d.%m.%Y",sprintf("%02d.%02d.%04d",d,m,y)))  # week day of certain date
wpy(y)      = wday(1,1,y)==4 || wday(31,12,y)==4 ? 53 : 52                   # weeks per year
woy(t)      = week(t) < 1 ? wpy(tm_year(t)-1) : \
              week(t) > wpy(tm_year(t)) ? 1 : week(t)                        # week of year
yow(t)      = int(week(t) < 1 ? tm_year(t)-1 : week(t) > wpy(tm_year(t)) ? \
              tm_year(t)+1 : tm_year(t))                                     # year of week (could be previous, current or next)

isot(d)     = strptime('%Y-%m-%d',d)                                         # iso date to time_t
isoddw(d)   = woy(isot(d)) %2 == 0 ? 0 : STATS_max                           # iso date to column height based on weekday oddity
isoddy(d)   = yow(isot(d)) %2 == 0 ? 0 : STATS_max                           # iso date to column height based on weekday oddity
bgcolw(d)   = isoddw(d) ? '#ffffff' : bg_altcolor1

set xlabel ""
set xtics 0,86400*7 nomirror font "Verdana, 7" # weekly grid tic

set ylabel "Reach" tc "".reach_color
set ytics 0,reach_ytics nomirror enhanced tc "".reach_color
set ytics add ("".reach_waterline reach_waterline)

set y2label "Total Followers" offset 0,1 rotate by -90 tc "".followers_color
set y2tics 50 nomirror tc "".followers_color

#set y3label "Followers / avg" offset 0,1 rotate by -90 tc "".followers_avg_color
#set y3tics 50 nomirror tc "".followers_avg_color

set grid
set boxwidth 0.05 absolute

# 1) plot "reach" csv column as vertical 1px-wide boxes
# 2) plot "followers (total)" csv column as growth line
# 3) attach "object" labels to "reach" boxes when "reach" value >= "reach_waterline"
# 4) attach "object" labels to "reach" boxes when "rank" value <= "rank_waterline"
plot filename  using 1:(isoddy(strcol(date_col))) with filledcurves above axis x1y1 lc "".bg_altcolor2,\
'' using 1:(isoddw(strcol(date_col))) with filledcurves above axis x1y1 lc "".bg_altcolor1,\
'' using 1:reach_col smooth freq with boxes axis x1y1 lc "".reach_color,\
'' using 1:followers_col with lines axis x1y2 lw 3 lt rgb "".followers_color title "∑(followers)",\
'' using 1:followers_avg_col with lines axis x1y2 lw 1 lt rgb "".followers_avg_color title "average(followers)",\
'' using 1:reach_col:(column(reach_col) >= reach_waterline ? sprintf("%s", strcol(object_col)) : "") with labels right offset -1.25,0 tc "".reach_color rotate by 90 title "",\
'' using 1:reach_col:(column(rank_col) <= rank_waterline ? sprintf("%s", strcol(object_col)) : "") with labels right offset -1.25,0 tc "".rank_color rotate by 90 title "",\
'' using 1:reach_avg_col with lines axis x1y1 lw 1 lt rgb "".reach_color title "average(reach)"

# min_y = GPVAL_DATA_Y_MIN
# max_y = GPVAL_DATA_Y_MAX
