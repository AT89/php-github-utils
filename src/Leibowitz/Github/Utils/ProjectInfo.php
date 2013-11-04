<?php

namespace Leibowitz\Github\Utils;

class ProjectInfo
{
    public function __construct($config)
    {
        $this->config = $config;

        $this->founds = array();

        $this->initGithubClient();
    }

    public function initGithubClient()
    {
        // Authentification
        $this->githubclient = new \Github\Client();

        $this->githubclient->authenticate(
            $this->config['token'],
            '',
            \Github\Client::AUTH_HTTP_TOKEN);

    }

    public function getGithubClient()
    {
        return $this->githubclient;
    }

    public function hasProject($key)
    {
        return array_key_exists($key, $this->config['projects']);
    }

    public function getProjectConfig($key = null)
    {
        return $this->config['projects'][ $key ?: $this->project_key ];
    }

    public function setProjectKey($key)
    {
        $this->project_key = $key;
    }

    public function getCommitDetails($commit, $project_key = null)
    {
        // start to query the github api about the commit
        // get info about the commit
        $project = $this->getProjectConfig($project_key);
        $commit_details = $this->getGithubClient()
            ->api('repo')
            ->commits()
            ->show($this->config['user'], $project['name'], $commit);

        return array(
            'author' => $commit_details['commit']['author']['name'],
            'date' => $commit_details['commit']['author']['date'],
            'message' => $commit_details['commit']['message'],
            'url' => $commit_details['html_url']
        );
    }

    public function getBranches($project_key = null)
    {
        $project = $this->getProjectConfig($project_key);
        return $this->getGithubClient()
            ->api('repo')
            ->branches($this->config['user'], $project['name']);
    }

    public function addFoundBranch($branch, $index = 0)
    {
        // store the branches names where the commit was found
        $this->founds[ $branch ] = $index;
    }

    public function getFoundBranches()
    {
        return array_keys($this->founds);
    }

    public function countFoundBranches()
    {
        return count($this->founds);
    }

    public function getPullRequests(
        $branch = 'master',
        $state = 'closed',
        $project_key = null)
    {
        $project = $this->getProjectConfig($project_key);
        return $this->getGithubClient()
            ->api('pull_request')
            ->all(
                $this->config['user'],
                $project['name'],
                array(
                    'state' => $state,
                    'base' => $branch,
                    // Get only latest 3 PR as otherwise github api
                    // tends to timeout
                    'per_page' => 3,
                ));
    }

    public function getAllBranchesSha($project_key = null)
    {
        $data = array();

        $branches = $this->getBranches($project_key);

        foreach($branches as $branch) {
            $data[ $branch['name'] ] = $branch['commit']['sha'];
        }

        $pullrequests = $this->getPullRequests(
            'master',
            'closed',
            $project_key);

        foreach($pullrequests as $preq) {
            $data[ $preq['head']['ref'] ] = $preq['head']['sha'];
        }

        return $data;
    }

    public function compareSha($branches, $commit)
    {
        foreach($branches as $branch => $sha) {
            if( $sha == $commit ) {
                $this->addFoundBranch( $branch );
            }
        }
    }

    public function getBranchesForCommit($commit, $project_key = null)
    {
        $branches = $this->getAllBranchesSha($project_key);

        // Don't check master just yet
        $master_branch = array('master' => $branches['master']);

        unset($branches['master']);

        // Look if we can find the commit at the top of a branch
        $this->compareSha($branches, $commit);

        // Compare with master
        $this->compareSha($master_branch, $commit);

        if( $this->countFoundBranches() == 0 ) {
            // Search in previous commits in master
            $this->searchBranches($master_branch, $commit, $project_key);
        }

        if( $this->countFoundBranches() == 0 ) {
            // Search in previous commits in all other branches
            $this->searchBranches($branches, $commit, $project_key);
        }

        return $this->getFoundBranches();
    }

    public function getBranchCommits($sha, $project_key = null)
    {
        $project = $this->getProjectConfig($project_key);
        return $this->getGithubClient()
            ->api('repo')
            ->commits()
            ->all(
                $this->config['user'],
                $project['name'],
                array('sha' => $sha) );
    }

    public function searchBranches($branches, $search_commit, $project)
    {
        foreach($branches as $branch => $sha) {
            $commits = $this->getBranchCommits($sha, $project);

            foreach($commits as $index => $commit) {
                if( $search_commit == $commit['sha'] ) {
                    // We found the commit in this branch
                    $this->addFoundBranch( $branch, $index );
                    break 2;
                }
            }
        }

    }
}
