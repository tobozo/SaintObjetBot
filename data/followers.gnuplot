# gnuplot script by @tobozo copyleft (c+) Aug 2024
set title "Grouchabot Mastodon Followers Growth"

set terminal pngcairo size width,height enhanced font 'Verdana,8'
set output 'cache/followers.png'
set datafile separator ","
# set key autotitle columnhead # use the first csv line as title
set key autotitle columnhead box opaque top left # use the first line as title

set xdata time # the X axis will be date/time data type
set timefmt '%Y-%m-%d' # how the date/time input must be parsed
set format x "%b\n%Y\nW%W" # output format on the X axis

# graph colors
follows_color = "#cc00cc"
follows_waterline = 8
followers_color = "#FF0000"

# csv column numbers
follows_col=4
followers_col=5
object_col=3

set xlabel "" # X axis label
set xtics 0,86400*7 nomirror # weekly grid tic

set ylabel "Followers / day" tc "".follows_color # Y axis label
set ytics 0,5 nomirror enhanced tc "".follows_color
set ytics add ("".follows_waterline follows_waterline)

set y2label "Followers / total" offset 0,1 rotate by -90 tc "".followers_color
set y2tics 50 nomirror tc "".followers_color

set grid
set boxwidth 0.05 absolute

# 1) plot "follows" csv column as vertical 1px-wide boxes
# 2) plot "followers (total)" csv column as growth line
# 3) attach "object" labels to "follows" boxes when "follows" value is over "follows_waterline"
plot filename using 1:follows_col smooth freq with boxes axis x1y1 lc "".follows_color,\
'' using 1:followers_col with lines axis x1y2 lw 4 lt rgb "".followers_color,\
'' using 1:follows_col:(column(follows_col) >= follows_waterline ? sprintf("%s", strcol(object_col)) : "") with labels right offset -1.25,0 tc "".follows_color rotate by 90 title ""

