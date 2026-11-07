# syntax=docker/dockerfile:1

# The local stand-in for a GitHub-hosted runner: a private Docker daemon with act beside it
# ({@see docs/ci.md}). The daemon is the docker-ce version GitHub's ubuntu-26.04 image pins, 29.4.2, which has no
# dind image; 29.4.3 is the nearest.
FROM docker:29.4.3-dind

ARG ACT_VERSION=0.2.97
ARG TARGETARCH

# The snapshot keeps the host owner, as a checkout keeps the runner user's.
RUN apk add --no-cache git && git config --system --add safe.directory '*'

RUN <<-EOF
	set -eu
	case "$TARGETARCH" in
		amd64) archive=act_Linux_x86_64.tar.gz ;;
		arm64) archive=act_Linux_arm64.tar.gz ;;
		*) echo "act publishes no build for $TARGETARCH" >&2; exit 1 ;;
	esac
	cd /tmp
	wget -q "https://github.com/nektos/act/releases/download/v${ACT_VERSION}/${archive}"
	wget -q "https://github.com/nektos/act/releases/download/v${ACT_VERSION}/checksums.txt"
	grep " ${archive}\$" checksums.txt | sha256sum -c -
	tar -xzf "$archive" -C /usr/local/bin act
	rm "$archive" checksums.txt
EOF

COPY actrc /root/.actrc
