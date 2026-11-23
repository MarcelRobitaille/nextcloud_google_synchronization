{
  description = "PHP 8.1 and Composer dev shell for google_synchronization";

  inputs = {
    # nixos-25.05 is the last nixpkgs release branch that still ships PHP 8.1.
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-25.05";
    # Composer 2.9.x matches upstream's plugin-api-version 2.9.0 and still
    # runs fine on PHP 8.1.
    composerNixpkgs.url = "github:NixOS/nixpkgs/nixos-25.11";
  };

  outputs = { self, nixpkgs, composerNixpkgs }:
    let
      pkgs = import nixpkgs { system = "x86_64-linux"; };
      composerPkgs = import composerNixpkgs { system = "x86_64-linux"; };
      
      # Default nixpkgs PHP extensions cover the project's needs; bz2 is
      # the only CI extension that is not enabled by default.
      php = pkgs.php81.withExtensions ({ all, enabled }: enabled ++ [ all.bz2 ]);
      composer = composerPkgs.php85.packages.composer.override { php = php; };

    in
    {
      devShell.x86_64-linux = pkgs.mkShell {
        name = "google-synchronization-php";

        packages = [
          php
          composer
          pkgs.git
          pkgs.curl
        ];

        env = {
          COMPOSER_MEMORY_LIMIT = "-1";
        };

        shellHook = ''
          echo "PHP:      $(php -v | head -n 1)"
          echo "Composer: $(composer --version | head -n 1)"
        '';
      };
    };
}
