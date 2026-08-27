# Spatie Version Control Guidelines (Reference)

## Repository Naming

### Site source code

Use the main domain name in lowercase, without `www`:
- Good: `spatie.be`
- Bad: `https://www.spatie.be`, `www.spatie.be`, `Spatie.be`

### Subdomains

Include the subdomain in the repo name:
- Good: `guidelines.spatie.be`
- Bad: `spatie.be-guidelines`

### Packages and other projects

Use kebab-case:
- Good: `laravel-backup`, `spoon`
- Bad: `LaravelBackup`, `Spoon`

## Branches

- Once a project is live, keep `main` stable and deployable at all times.
- Treat all branches as active and clean up stale branches.

### Initial development

- Maintain `main` and `develop` branches.
- Commit through `develop`, not directly to `main`.
- Feature branches are optional; if used, branch from `develop`.

### Live projects

- Delete the `develop` branch.
- All commits to `main` must come through feature branches.
- Prefer squashing commits on merge.

### Branch naming

- Use lowercase letters and hyphens only.
- Good: `feature-mailchimp`, `fix-deliverycosts`, `updates-june-2016`
- Bad: `feature/mailchimp`, `random-things`, `develop`

## Pull Requests

- Pull requests are optional but useful for peer review, merge validation, and historical reference.

## Merging and Rebasing

- Rebase regularly to reduce merge conflicts.
- For deploying feature branches, use `git merge <branch> --squash`.
- If a push is denied, use `git rebase` rather than merge.

## Commits

- Descriptive messages are recommended during initial development and required after launch.
- Always use present tense.
- Good: `Update deps`, `Fix vat calculation in delivery costs`
- Bad: `wip`, `commit`, `a lot`, `solid`
- Prefer small, focused commits over large ones.

## Git Tips

### Split changes into granular commits

Use `git add -p` to interactively select the chunks to stage.

### Move local commits to a new branch

Create the new branch before resetting and checking it out. Do not do this to pushed commits without first checking with collaborators.

```bash
git branch my-branch
git reset --hard HEAD~3 # OR git reset --hard <commit>

git checkout my-branch
```

### Squash commits that are already pushed

Only do this when nobody else has pushed changes during those commits. Copy the SHA immediately before the commits to squash, then:

```bash
git reset --soft <commit>
git commit -m "your new message"
git push --force
```

### Clean up local branches

Prune branches that no longer exist upstream. Use `--dry-run` first if there is any doubt.

```bash
git remote prune origin --dry-run
git remote prune origin
```

## Resources

- [GitHub Flow](https://guides.github.com/introduction/flow/)
- [Merge vs. rebase on Atlassian](https://www.atlassian.com/git/tutorials/merging-vs-rebasing/workflow-walkthrough)
- [Merge vs. rebase by @porteneuve](https://medium.com/@porteneuve/getting-solid-at-git-rebase-vs-merge-4fa1a48c53aa)

---

Source: https://spatie.be/guidelines/version-control
