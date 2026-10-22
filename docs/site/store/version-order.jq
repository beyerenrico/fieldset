# Orders two docs copy tags for the privileged store job (docs site plan U5 and U7, KTD "Version rules decide every
# publish"). store runs no repo code except this program and store-artifact.sh beside it, which it reads from main,
# never from the build artifact.
#
#   jq -n --arg a <tag> --arg b <tag> -f docs/site/store/version-order.jq
#
# Prints -1, 0 or 1: a ranks below, equal to or above b. It accepts only the tags that get a copy: vX.Y.Z and
# vX.Y.Z-rc.N, numbers without leading zeros. Anything else (alpha, beta, a bare or dotted rc, build metadata, a
# trailing newline) stops jq with exit status 5, nothing on stdout and "version-order: ... is not a docs copy tag" on
# stderr. A final ranks above its rc.N; numbers compare by length, then digit by digit, so any size orders exactly
# (`sort -V` puts 2.1.0 before 2.1.0-rc.1 and is not used). test/fixtures/version-vectors.json holds the agreed order;
# docs/site/test/version-order-jq.test.mjs runs it.

def copy_tag_key:
  if type == "string"
    and test("\\Av(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)(-rc\\.(0|[1-9][0-9]*))?\\z")
  then capture("\\Av(?<x>[0-9]+)\\.(?<y>[0-9]+)\\.(?<z>[0-9]+)(-rc\\.(?<rc>[0-9]+))?\\z")
    | [.x, .y, .z | [length, .]] + [if .rc == null then [1] else [0, (.rc | [length, .])] end]
  else error("version-order: \(tojson) is not a docs copy tag (vX.Y.Z or vX.Y.Z-rc.N)")
  end;

($a | copy_tag_key) as $left
| ($b | copy_tag_key) as $right
| if $left < $right then -1 elif $left > $right then 1 else 0 end
