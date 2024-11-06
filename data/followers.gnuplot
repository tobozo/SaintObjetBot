# gnuplot script by @tobozo copyleft (c+) Aug 2024


set title outputtitle # "Grouchabot Mastodon Followers Growth"

set terminal pngcairo size width,height enhanced font 'Verdana,8'
set output outputfilename # 'cache/followers.png'
set datafile separator ","
# set key autotitle columnhead # use the first csv line as title
set key autotitle columnhead box opaque top left # use the first line as title

# stats [1:13] filename
# min_blah = stats_min_y
# max_blah = stats_max_y
# set blah [GPVAL_DATA_Y_MIN:GPVAL_DATA_Y_MAX]

# stats [1:13] filename
# plot [stats_min_x:stats_max_x][stats_min_y:stats_max_y] filename


# graph colors and waterline
bg_altcolor1 = '#80eeeeee' # AARRGGBB
bg_altcolor2 = '#80fafafa' # AARRGGBB
followers_color = "#FF0000"
# total_reach_color = "#00FF80"
perf_color = "#cc00cc"
perf_waterline = 20
reach_waterline = 5000

# csv column numbers
date_col = 1
perf_col = 13
followers_col=5
reach_col=6
object_col=3
# total_reach_col = 14

stats filename using ($6) nooutput

# print "STATS_records=", STATS_records
# print "STATS_invalid=", STATS_invalid
# print "STATS_blank=", STATS_blank
# print "STATS_min=", STATS_min
# print "STATS_max=", STATS_max


set xdata time # the X axis will be date/time data type
set timefmt '%Y-%m-%d' # how the date/time input must be parsed
# %b = month abbreviated, %Y = full year, %W = week number
set format x "%b\n%Y\nW%W" # output format on the X axis


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


set xlabel "" # X axis label
set xtics 0,86400*7 nomirror font "Verdana, 7" # weekly grid tic

set ylabel "" # "Performance (reach / followers)" tc "".perf_color # Y axis label
# set ytics format " " 0,2 nomirror tc "#00ffffff"
# set ytics scale 0,1 tc "#00ffffff"
set ytics 0,1000 nomirror enhanced tc "".perf_color
set ytics add ("".reach_waterline reach_waterline)

set y2label "Followers / total" offset 0,1 rotate by -90 tc "".followers_color
set y2tics 50 nomirror tc "".followers_color

set grid xtics y2tics
set boxwidth 0.05 absolute

# 1) plot "performance" csv column as vertical 1px-wide boxes
# 2) plot "followers (total)" csv column as growth line
# 3) attach "object" labels to "performance" boxes when "performance" value is over "perf_waterline"
set style fill solid

# plot filename using 1:perf_col
# set yr [GPVAL_DATA_Y_MIN:GPVAL_DATA_Y_MAX]
# replot

# xlast = 0
# parity = 0
#
#
# do for [i = 1:STATS_records] {
#     if( i%2==0 ) {
#
#     } else {
#         print sprintf("%d is odd", i)
#     }
#
# }

#plot for [i = 1:STATS_records] filename u 1:(isoddw(strcol(date_col))) {
    # set lc "".bgcolw(strcol(date_col))
    # set style line 5 lt "".bgcolw(strcol(date_col))
    # x =
    # x = x0 + i*dx
    # set arrow from x,y0 to x,y1 nohead linecolor "blue" # add other styling options if needed
#}

#plot for [idx=STATS_records:1] filename \
#    u 1:(isoddw(strcol(date_col)))



plot filename using 1:(isoddy(strcol(date_col))) with filledcurves above axis x1y1 lc "".bg_altcolor2,\
'' using 1:(isoddw(strcol(date_col))) with filledcurves above axis x1y1 lc "".bg_altcolor1,\
'' using 1:followers_col with lines axis x1y2 lw 4 lt rgb "".followers_color,\
'' using 1:reach_col smooth freq with boxes axis x1y1 lc "".perf_color,\
'' using 1:reach_col:(column(reach_col) >= reach_waterline ? sprintf("%s", strcol(object_col)) : "") with labels right offset -1.25,0 tc "".perf_color rotate by 90 title ""

# '' using 1:(65) with lines axis x1y1,\
# '' using 1:(1):2 with boxes linecolor palette axes x1y2, \

