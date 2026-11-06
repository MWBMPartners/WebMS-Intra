# D-067 · The owner removes the two leftover Docker volumes

**Date:** 2026-11-06
**Status:** in force

**Question:** two leftover Docker storage areas — "dangling volumes", disk space
a throwaway test database left behind after its container was removed — have been
on the blocked list since 30 October and 3 November. Deleting one is refused by
the permission classifier, correctly, because it cannot be undone. Who removes
them?
**Answer:** **the owner removes them, from two exact commands naming the two
identifiers and nothing else.** No assistant touches them.
**Rules out:** granting an assistant permission for those two identifiers;
leaving them for ever with nothing recording why. It emphatically rules out
`docker volume prune` and `docker system prune`, which would take other projects'
volumes with them.
**Why:** the rule reserving destructive cleanup for the owner exists because a
sweep cannot tell one project's leftovers from another's. An identifier-by-
identifier delete the owner runs themselves is the smallest possible exception to
it, and the refusal that prompted this question was the backstop working as
designed.
**Where:** the two commands were given in the session of 6 November 2026:

    docker volume rm cfbd1277f4cb2a4a8de5367a4cdd5e00087486d6c0ff381dec946379f2b870f7
    docker volume rm f4c7e62cac21f7efebe318b12013d95e5864424a3d2f8d7bca8c111b0133ad24

Neither is from this run — the count was 2 before and 2 after every package.
Once they are gone the blocked-list row goes with them.
