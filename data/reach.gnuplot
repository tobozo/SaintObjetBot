# gnuplot script by @tobozo copyleft (c+) Aug 2024
set title "Grouchabot Mastodon Reach vs Followers"

# graph colors
rank_color = "#000000"
reach_color = "#cc00cc"
reach_waterline = 3500
followers_color = "#FF8888"
followers_avg_color = "#ccccff"

# csv column numbers
rank_col=7
reach_col=6
followers_col=5
followers_avg_col=11
object_col=3

set terminal pngcairo size width,height enhanced font 'Verdana,8'
set output 'cache/reach.png'
set datafile separator ","
set key autotitle columnhead box opaque top left # use the first line as title
#set key inside bottom center horizontal font "Verdana, 8" width 1.8

set xdata time # the X axis will be date/time data type
set timefmt '%Y-%m-%d' # how the date/time input must be parsed
set format x "%b\n%Y\nW%W" # output format on the X axis

set xlabel ""
set xtics 0,86400*7 nomirror # weekly grid tic

set ylabel "Reach" tc "".reach_color
set ytics 0,500 nomirror enhanced tc "".reach_color
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
# 4) attach "object" labels to "reach" boxes when "rank" value <= "10"
plot filename using 1:reach_col smooth freq with boxes axis x1y1 lc "".reach_color,\
'' using 1:followers_col with lines axis x1y2 lw 3 lt rgb "".followers_color title "∑(followers)",\
'' using 1:followers_avg_col with lines axis x1y2 lw 1 lt rgb "".followers_avg_color title "average(followers)",\
'' using 1:reach_col:(column(reach_col) >= reach_waterline ? sprintf("%s", strcol(object_col)) : "") with labels right offset -1.25,0 tc "".reach_color rotate by 90 title "",\
'' using 1:reach_col:(column(rank_col) <= 10 ? sprintf("%s", strcol(object_col)) : "") with labels right offset -1.25,0 tc "".rank_color rotate by 90 title ""

# min_y = GPVAL_DATA_Y_MIN
# max_y = GPVAL_DATA_Y_MAX
