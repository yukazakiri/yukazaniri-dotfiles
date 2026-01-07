#!/usr/bin/env bash
set -euo pipefail

XDG_CONFIG_HOME="${XDG_CONFIG_HOME:-$HOME/.config}"
XDG_STATE_HOME="${XDG_STATE_HOME:-$HOME/.local/state}"
STATE_DIR="$XDG_STATE_HOME/quickshell"

scss_file="$STATE_DIR/user/generated/material_colors.scss"
kitty_conf="$XDG_CONFIG_HOME/kitty/kitty.conf"
foot_conf="$XDG_CONFIG_HOME/foot/foot.ini"

if [ ! -f "$scss_file" ]; then
  echo "[update-terminals] Missing $scss_file" >&2
  exit 1
fi

get_scss() {
  local key="$1"
  awk -v k="$key" 'BEGIN{FS=": "} $1=="$"k {gsub(";","",$2); print $2; exit}' "$scss_file"
}

bg=$(get_scss "background")
fg=$(get_scss "onBackground")
selection=$(get_scss "surfaceVariant")
selection_fg=$(get_scss "onSurface")
cursor=$(get_scss "primary")
cursor_text=$(get_scss "onPrimary")

if [ -z "$bg" ] || [ -z "$fg" ]; then
  echo "[update-terminals] Missing base colors in $scss_file" >&2
  exit 1
fi

term_colors=()
for i in $(seq 0 15); do
  value=$(get_scss "term$i")
  term_colors+=("$value")
done

# ---------------------
# Kitty config updates
# ---------------------
kitty_block_file="$(mktemp)"
cat > "$kitty_block_file" << EOF
# BEGIN AUTO THEME (quickshell)
# Theme colors (niri/quickshell material colors)
background $bg
foreground $fg
selection_background $selection
selection_foreground $selection_fg
cursor $cursor
cursor_text_color $cursor_text

# 16-color palette
color0  ${term_colors[0]}
color1  ${term_colors[1]}
color2  ${term_colors[2]}
color3  ${term_colors[3]}
color4  ${term_colors[4]}
color5  ${term_colors[5]}
color6  ${term_colors[6]}
color7  ${term_colors[7]}
color8  ${term_colors[8]}
color9  ${term_colors[9]}
color10 ${term_colors[10]}
color11 ${term_colors[11]}
color12 ${term_colors[12]}
color13 ${term_colors[13]}
color14 ${term_colors[14]}
color15 ${term_colors[15]}
# END AUTO THEME (quickshell)
EOF

if [ -f "$kitty_conf" ]; then
  if grep -q "# BEGIN AUTO THEME (quickshell)" "$kitty_conf"; then
    awk -v block_file="$kitty_block_file" '
      function print_block() { while ((getline line < block_file) > 0) print line; close(block_file) }
      /# BEGIN AUTO THEME \(quickshell\)/ { print_block(); in_block=1; next }
      /# END AUTO THEME \(quickshell\)/ { if (in_block) { in_block=0; next } }
      !in_block { print }
    ' "$kitty_conf" > "$kitty_conf.tmp" && mv "$kitty_conf.tmp" "$kitty_conf"
  else
    printf "\n" >> "$kitty_conf"
    cat "$kitty_block_file" >> "$kitty_conf"
  fi
fi

rm -f "$kitty_block_file"

# ---------------------
# Foot config updates
# ---------------------
if [ -f "$foot_conf" ]; then
  alpha_line=$(awk '
    /^\[colors\]$/ { in_block=1; next }
    in_block && /^alpha=/ { print; exit }
    in_block && /^\[/ { in_block=0 }
  ' "$foot_conf")
  if [ -z "$alpha_line" ]; then
    alpha_line="alpha=0.78"
  fi

  foot_block_file="$(mktemp)"
  cat > "$foot_block_file" << EOF
$alpha_line
background=${bg#\#}
foreground=${fg#\#}
selection-background=${selection#\#}
selection-foreground=${selection_fg#\#}
regular0=${term_colors[0]#\#}
regular1=${term_colors[1]#\#}
regular2=${term_colors[2]#\#}
regular3=${term_colors[3]#\#}
regular4=${term_colors[4]#\#}
regular5=${term_colors[5]#\#}
regular6=${term_colors[6]#\#}
regular7=${term_colors[7]#\#}
bright0=${term_colors[8]#\#}
bright1=${term_colors[9]#\#}
bright2=${term_colors[10]#\#}
bright3=${term_colors[11]#\#}
bright4=${term_colors[12]#\#}
bright5=${term_colors[13]#\#}
bright6=${term_colors[14]#\#}
bright7=${term_colors[15]#\#}
EOF

  if grep -q "^\[colors\]$" "$foot_conf"; then
    awk -v block_file="$foot_block_file" '
      function print_block() { while ((getline line < block_file) > 0) print line; close(block_file) }
      /^\[colors\]$/ { print; print_block(); in_block=1; next }
      in_block && /^\[/ { in_block=0 }
      in_block { next }
      { print }
    ' "$foot_conf" > "$foot_conf.tmp" && mv "$foot_conf.tmp" "$foot_conf"
  else
    printf "\n[colors]\n" >> "$foot_conf"
    cat "$foot_block_file" >> "$foot_conf"
  fi

  rm -f "$foot_block_file"
fi
